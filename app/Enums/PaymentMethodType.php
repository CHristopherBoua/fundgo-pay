<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case SepaDebit = 'sepa_debit';
    case Card = 'card';
    case EcobankAccount = 'ecobank_account'; // débit de compte Ecobank validé par OTP (Ecobank Authenticator)

    public function label(): string
    {
        return match ($this) {
            self::SepaDebit => 'Prélèvement SEPA',
            self::Card => 'Carte bancaire',
            self::EcobankAccount => 'Compte Ecobank',
        };
    }

    /** Moyens proposés pour une devise (config payments.currencies.*.methods). */
    public static function forCurrency(string $currency): array
    {
        return array_values(array_filter(array_map(
            fn (string $v) => self::tryFrom($v),
            (array) config('payments.currencies.'.strtoupper($currency).'.methods', []),
        )));
    }
}
