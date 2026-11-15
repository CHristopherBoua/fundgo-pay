<?php

namespace App\Models;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'gateway', 'type', 'status',
        'gateway_customer_ref', 'gateway_mandate_ref', 'gateway_token',
        'rum', 'holder_name', 'iban_last4', 'iban_country', 'iban_fingerprint', 'bic',
        'card_brand', 'card_last4', 'card_expiry',
        'consent', 'proof', 'signed_at', 'revoked_at',
    ];

    protected $hidden = ['gateway_customer_ref', 'gateway_mandate_ref', 'gateway_token', 'iban_fingerprint', 'proof'];

    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'status' => PaymentMethodStatus::class,
            'consent' => 'array',
            'proof' => 'array',
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recurringDonations(): HasMany
    {
        return $this->hasMany(RecurringDonation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PaymentMethodStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === PaymentMethodStatus::Active;
    }

    public function isSepa(): bool
    {
        return $this->type === PaymentMethodType::SepaDebit;
    }

    public function displayName(): string
    {
        return $this->isSepa()
            ? sprintf('IBAN %s •••• %s', $this->iban_country, $this->iban_last4)
            : sprintf('%s •••• %s', ucfirst((string) $this->card_brand), $this->card_last4);
    }
}
