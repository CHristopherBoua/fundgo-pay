<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayEvent extends Model
{
    protected $fillable = [
        'gateway', 'event_id', 'type', 'gateway_reference', 'payload',
        'payload_sha256', 'attempts', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
