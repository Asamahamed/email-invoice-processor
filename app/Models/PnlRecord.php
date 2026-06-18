<?php
// app/Models/PnlRecord.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PnlRecord extends Model
{
    use SoftDeletes;

    protected $table = 'pnl_records';

    protected $fillable = [
        'sno',
        'message_id',
        'from_email',
        'from_address',
        'from_name',
        'subject',
        'body',
        'body_html',
        'received_at',
        'vendor_name',
        'invoice_number',
        'is_number',
        'invoice_date',
        'amount',
        'currency',
        'country_code',
        'exchange_rate_used',
        'category',
        'status',
        'read_status',
        'has_attachments',
        'extracted_data',
        'processing_status',
        'tour_ref',
        'agent_name',
        'credit_type',
        'classification_reason',
          'profit_loss',
              'total_pax',
    'total_nights',

    ];

    protected $casts = [
        'received_at' => 'datetime',
        'invoice_date' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate_used' => 'decimal:4',
        'has_attachments' => 'boolean',
        'extracted_data' => 'array'
    ];

    public function items()
    {
        return $this->hasMany(PnlItem::class, 'pnl_record_id');
    }
}