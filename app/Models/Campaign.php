<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'slug', 'title', 'summary', 'description', 'currency', 'goal_cents',
        'status', 'allows_recurring', 'tax_deductible', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'goal_cents' => 'integer',
            'allows_recurring' => 'boolean',
            'tax_deductible' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open'
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** Montant collecté (paiements encaissés, nets des remboursements / rejets). */
    public function raisedCents(): int
    {
        return (int) $this->payments()->where('status', PaymentStatus::Paid)->sum('amount_cents');
    }

    public function progressPercent(): ?int
    {
        if (! $this->goal_cents) {
            return null;
        }

        return (int) min(100, floor($this->raisedCents() * 100 / $this->goal_cents));
    }

    /** Compte du grand livre qui porte les fonds affectés à cette campagne. */
    public function fundAccount(): string
    {
        return 'fund:campaign:'.$this->id;
    }
}
