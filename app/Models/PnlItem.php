<?php
// app/Models/PnlItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PnlItem extends Model
{
    use SoftDeletes;

    protected $table = 'pnl_items';

    protected $fillable = [
        'pnl_record_id',
        'control_number',
        'invoice_number',
        'start_date',
        'end_date',
        'type',
        'credit_type',
        'agent_name',
        'client_name',
        'check_in_date',
        'check_out_date',
        'hotel_name',
        'transport_name',
        'service_name',
        'country_code',
        'currency',
        'amount_original',
        'exchange_rate',
        'amount_converted',
        'item_details',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'amount_original' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_converted' => 'decimal:2',
        'item_details' => 'array',
    ];

    public function pnlRecord()
    {
        return $this->belongsTo(PnlRecord::class);
    }
}