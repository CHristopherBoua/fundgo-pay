<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case SepaDebit = 'sepa_debit';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::SepaDebit => 'Prélèvement SEPA',
            self::Card => 'Carte bancaire',
        };
    }
}
