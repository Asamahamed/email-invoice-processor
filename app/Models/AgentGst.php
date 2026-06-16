<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentGst extends Model
{
    protected $fillable = [
        'agent_name',
        'gst_number',
        'agent_code',
    ];
}