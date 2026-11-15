<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Écriture du grand livre. Append-only : protégé ici ET par des triggers en base.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'amount_cents' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ledger_entries is append-only'));
        static::deleting(fn () => throw new LogicException('ledger_entries is append-only'));
    }
}
