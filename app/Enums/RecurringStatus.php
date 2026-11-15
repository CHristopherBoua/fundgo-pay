<?php

namespace App\Enums;

enum RecurringStatus: string
{
    case Pending = 'pending';     // en attente du premier paiement / de l'activation du mandat
    case Active = 'active';
    case Paused = 'paused';       // suspendu par le donateur (ou suite à un rejet)
    case PastDue = 'past_due';    // relances épuisées : action du donateur requise
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Active => 'Actif',
            self::Paused => 'En pause',
            self::PastDue => 'Paiement à régulariser',
            self::Cancelled => 'Arrêté',
        };
    }
}
