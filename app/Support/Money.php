<?php

namespace App\Support;

final class Money
{
    public static function format(int $cents, string $currency = 'EUR'): string
    {
        $formatter = new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($cents / 100, $currency);
    }

    /** "12,50" | "12.50" | "12" -> 1250. */
    public static function toCents(string|int|float $amount): int
    {
        $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $amount);

        return (int) round(((float) $normalized) * 100);
    }
}
