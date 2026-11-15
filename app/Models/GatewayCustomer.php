<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayCustomer extends Model
{
    protected $fillable = ['user_id', 'gateway', 'reference'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
