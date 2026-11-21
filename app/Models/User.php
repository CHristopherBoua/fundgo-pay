<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_DONOR = 'donor';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPERADMIN = 'superadmin';

    /** `role` et les champs 2FA ne sont volontairement PAS assignables en masse. */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
    ];

    protected $attributes = ['role' => self::ROLE_DONOR];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPERADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function recurringDonations(): HasMany
    {
        return $this->hasMany(RecurringDonation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function gatewayCustomers(): HasMany
    {
        return $this->hasMany(GatewayCustomer::class);
    }

    public function taxReceipts(): HasMany
    {
        return $this->hasMany(TaxReceipt::class);
    }

    /** Mentions obligatoires du cerfa 11580 : nom et adresse complète du donateur. */
    public function hasCompleteAddress(): bool
    {
        return filled($this->address_line1) && filled($this->postal_code) && filled($this->city);
    }
}
