<?php
// app/Models/GeneratedInvoice.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GeneratedInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'generated_invoices';

    protected $fillable = [
        'email_id',
        'invoice_number',
        'invoice_date',
        'customer_name',
        'guest_name',
        'tour_ref',
        'total_amount',
        'handling_fee',
        'grand_total',
        'currency',
        'invoice_type',
        'status',
        'file_path',
        'calculations'
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'total_amount' => 'decimal:2',
        'handling_fee' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'calculations' => 'array'
    ];

    public function email()
    {
        return $this->belongsTo(IncomingEmail::class, 'email_id');
    }
}