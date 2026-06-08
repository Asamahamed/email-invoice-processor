<?php
// app/Models/IncomingEmail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingEmail extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'message_id',
        'from_email',
        'from_name',
        'subject',
        'body',
        'body_preview',
        'received_at',
        'agent_name',
        'guest_name',
        'tour_ref',
        'file_handler',
        'travel_start_date',
        'travel_end_date',
        'number_of_guests',
        'pax_count',                    // ADDED - number of passengers
        'destination',
        'total_amount',
        'currency',
        'cost_per_person',
        'invoice_number',
        'reference_no',                 // ADDED - reference number
        'exchange_rate',                // ADDED - USD to INR exchange rate
        'handling_fee_percent',         // ADDED - handling fee percentage (0.5%)
        'cgst_percent',                 // ADDED - CGST percentage (9%)
        'sgst_percent',                 // ADDED - SGST percentage (9%)
        'credit_type',
        'classification_reason',
        'read_status',
        'processing_status',
        'has_attachments',
        'is_tour_confirmation',
        'email_metadata',
        'error_message'
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'travel_start_date' => 'date',
        'travel_end_date' => 'date',
        'total_amount' => 'decimal:2',
        'cost_per_person' => 'decimal:2',
        'exchange_rate' => 'decimal:2',         // ADDED
        'handling_fee_percent' => 'decimal:2',  // ADDED
        'cgst_percent' => 'decimal:2',          // ADDED
        'sgst_percent' => 'decimal:2',          // ADDED
        'has_attachments' => 'boolean',
        'is_tour_confirmation' => 'boolean',
        'email_metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function attachments()
    {
        return $this->hasMany(EmailAttachment::class, 'email_id');
    }

    public function invoice()
    {
        return $this->hasOne(GeneratedInvoice::class, 'email_id');
    }

    // Scopes
    public function scopeUnread($query)
    {
        return $query->where('read_status', 'unread');
    }

    public function scopeRead($query)
    {
        return $query->where('read_status', 'read');
    }

    public function scopeCredit($query)
    {
        return $query->where('credit_type', 'credit');
    }

    public function scopeNonCredit($query)
    {
        return $query->where('credit_type', 'non_credit');
    }

    public function scopeTourConfirmations($query)
    {
        return $query->where('is_tour_confirmation', true);
    }

    // Accessors
    public function getCreditTypeBadgeAttribute()
    {
        return match($this->credit_type) {
            'credit' => '<span class="badge bg-success">Credit</span>',
            'non_credit' => '<span class="badge bg-warning">Non-Credit</span>',
            default => '<span class="badge bg-secondary">Pending</span>'
        };
    }

    public function getReadStatusBadgeAttribute()
    {
        return $this->read_status === 'read' 
            ? '<span class="badge bg-success">Read</span>' 
            : '<span class="badge bg-info">Unread</span>';
    }

    public function getFormattedTotalAmountAttribute()
    {
        if (!$this->total_amount) return 'N/A';
        return ($this->currency ?? 'USD') . ' ' . number_format($this->total_amount, 2);
    }

    // ADDED - Helper method to get formatted travel dates
    public function getFormattedTravelDatesAttribute()
    {
        if ($this->travel_start_date) {
            $start = $this->travel_start_date->format('d/m/Y');
            if ($this->travel_end_date) {
                $end = $this->travel_end_date->format('d/m/Y');
                return "{$start} - {$end}";
            }
            return $start;
        }
        return null;
    }

    // ADDED - Helper method to get exchange rate with +1 rule
    public function getCalculatedExchangeRateAttribute()
    {
        // XE.com rate + 1 (default 96+1=97)
        return $this->exchange_rate ?? 97;
    }

    // ADDED - Helper method to get handling fee amount in USD
    public function getHandlingFeeUsdAttribute()
    {
        $percent = $this->handling_fee_percent ?? 0.5;
        return ($this->total_amount ?? 0) * ($percent / 100);
    }

    // ADDED - Helper method to get net amount after handling fee
    public function getNetAmountUsdAttribute()
    {
        return ($this->total_amount ?? 0) - $this->handling_fee_usd;
    }

    // ADDED - Helper method to get total INR calculation
    public function getCalculatedTotalInrAttribute()
    {
        $netUsd = $this->net_amount_usd;
        $exchangeRate = $this->calculated_exchange_rate;
        $pax = $this->pax_count ?? 1;
        
        $baseAmountINR = $netUsd * $exchangeRate * $pax;
        $handlingFeeINR = $this->handling_fee_usd * $exchangeRate * $pax;
        $subTotalINR = $baseAmountINR + $handlingFeeINR;
        
        $cgst = $subTotalINR * (($this->cgst_percent ?? 9) / 100);
        $sgst = $subTotalINR * (($this->sgst_percent ?? 9) / 100);
        
        return $subTotalINR + $cgst + $sgst;
    }
}