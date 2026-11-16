<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Frequency;
use App\Enums\RecurringStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringDonation extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'campaign_id', 'payment_method_id', 'amount_cents', 'currency',
        'frequency', 'day_of_month', 'status', 'next_charge_on', 'prenotified_for',
        'paused_until', 'status_reason', 'started_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'day_of_month' => 'integer',
            'frequency' => Frequency::class,
            'status' => RecurringStatus::class,
            'next_charge_on' => DateOnly::class,
            'prenotified_for' => DateOnly::class,
            'paused_until' => DateOnly::class,
            'started_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [RecurringStatus::Active, RecurringStatus::Paused, RecurringStatus::PastDue], true);
    }
}
