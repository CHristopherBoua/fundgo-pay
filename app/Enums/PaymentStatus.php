<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';         // créé localement, pas encore soumis au PSP
    case Processing = 'processing';   // soumis, en attente (SEPA : D+2 à D+5)
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case ChargedBack = 'charged_back'; // rejet / retour SEPA (R-transaction) ou litige carte

    /** Transitions autorisées : tout le reste est ignoré (webhook en retard, doublon…). */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Processing, self::Paid, self::Failed, self::Cancelled],
            self::Processing => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::Refunded, self::ChargedBack],
            self::Failed, self::Cancelled, self::Refunded, self::ChargedBack => [],
        }, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Processing => 'En cours',
            self::Paid => 'Payé',
            self::Failed => 'Échoué',
            self::Cancelled => 'Annulé',
            self::Refunded => 'Remboursé',
            self::ChargedBack => 'Rejeté',
        };
    }
}
