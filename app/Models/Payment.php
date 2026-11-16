<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'donation_id', 'recurring_donation_id', 'payment_method_id', 'campaign_id',
        'gateway', 'method_type', 'sequence_type', 'amount_cents', 'currency', 'status',
        'period', 'attempt_no', 'gateway_reference', 'failure_code', 'failure_message',
        'next_retry_at', 'submitted_at', 'paid_at', 'failed_at', 'reversed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'attempt_no' => 'integer',
            'status' => PaymentStatus::class,
            'method_type' => PaymentMethodType::class,
            'sequence_type' => SequenceType::class,
            'period' => DateOnly::class,
            'next_retry_at' => 'datetime',
            'submitted_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function recurringDonation(): BelongsTo
    {
        return $this->belongsTo(RecurringDonation::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function description(): string
    {
        $label = $this->campaign?->title ?? config('app.name');

        return $this->recurring_donation_id
            ? sprintf('Don régulier - %s - %s', $label, $this->period?->format('m/Y'))
            : sprintf('Don - %s', $label);
    }

    /** Compte « fonds » crédité lorsque ce paiement est encaissé. */
    public function fundAccount(): string
    {
        return $this->campaign_id ? 'fund:campaign:'.$this->campaign_id : 'fund:general';
    }

    public function clearingAccount(): string
    {
        return 'psp:'.$this->gateway.':clearing';
    }
}
