<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Reçu fiscal émis. Immuable : seules l'annulation (remplacement par un rectificatif)
 * et la date de premier téléchargement peuvent évoluer.
 */
class TaxReceipt extends Model
{
    use HasUlids;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    private const MUTABLE = ['status', 'cancelled_at', 'cancellation_reason', 'downloaded_at', 'updated_at'];

    protected $fillable = [
        'number', 'user_id', 'fiscal_year', 'amount_cents', 'currency', 'status', 'payment_ids',
        'donor', 'organization', 'payments', 'content_sha256', 'issued_at', 'replaces_id',
        'cancelled_at', 'cancellation_reason', 'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'amount_cents' => 'integer',
            'payment_ids' => 'array',
            'donor' => 'array',
            'organization' => 'array',
            'payments' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TaxReceipt $receipt) {
            $changed = array_diff(array_keys($receipt->getDirty()), self::MUTABLE);
            if ($changed !== []) {
                throw new LogicException('Tax receipts are immutable; changed: '.implode(', ', $changed));
            }
        });
        static::deleting(fn () => throw new LogicException('Tax receipts cannot be deleted; cancel and reissue instead'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function estimatedReductionCents(): int
    {
        return (int) floor($this->amount_cents * (int) ($this->organization['reduction_rate_percent'] ?? 66) / 100);
    }

    public function filename(): string
    {
        return 'recu-fiscal-'.$this->number.'.pdf';
    }
}
