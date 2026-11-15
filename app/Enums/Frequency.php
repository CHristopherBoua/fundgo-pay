<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum Frequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }

    /** Échéance suivante, en conservant le jour choisi (borné à 28 : existe tous les mois). */
    public function next(CarbonImmutable $from, int $dayOfMonth): CarbonImmutable
    {
        return $from->startOfMonth()->addMonthsNoOverflow($this->months())->setDay(min($dayOfMonth, 28));
    }

    /** Première échéance >= $earliest tombant le jour choisi. */
    public static function firstOccurrence(CarbonImmutable $earliest, int $dayOfMonth): CarbonImmutable
    {
        $candidate = $earliest->startOfMonth()->setDay(min($dayOfMonth, 28));

        return $candidate->lt($earliest->startOfDay()) ? $candidate->addMonthNoOverflow() : $candidate;
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensuel',
            self::Quarterly => 'Trimestriel',
            self::Yearly => 'Annuel',
        };
    }

    public function perLabel(): string
    {
        return match ($this) {
            self::Monthly => '/ mois',
            self::Quarterly => '/ trimestre',
            self::Yearly => '/ an',
        };
    }
}
