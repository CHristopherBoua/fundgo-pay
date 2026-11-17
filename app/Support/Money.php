<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Montants en unités mineures entières, quelle que soit la devise
 * (centimes EUR/USD, kobo NGN, francs XOF/XAF qui n'ont pas de subdivision).
 */
final class Money
{
    public static function decimals(string $currency): int
    {
        $config = config('payments.currencies.'.strtoupper($currency));
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unsupported currency [$currency]");
        }

        return (int) $config['decimals'];
    }

    public static function isSupported(string $currency): bool
    {
        return is_array(config('payments.currencies.'.strtoupper($currency)));
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(config('payments.currencies', []));
    }

    public static function format(int $minor, string $currency = 'EUR'): string
    {
        $formatter = new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY);
        $decimals = self::decimals($currency);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);

        return $formatter->formatCurrency(self::toMajor($minor, $currency), strtoupper($currency));
    }

    /** "12,50" | "12.50" | "5 000" -> unités mineures de la devise. */
    public static function toMinor(string|int|float $amount, string $currency = 'EUR'): int
    {
        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) $amount);

        return (int) round(((float) $normalized) * (10 ** self::decimals($currency)));
    }

    /** @deprecated alias historique (EUR) — utiliser toMinor() */
    public static function toCents(string|int|float $amount, string $currency = 'EUR'): int
    {
        return self::toMinor($amount, $currency);
    }

    public static function toMajor(int $minor, string $currency = 'EUR'): float
    {
        return $minor / (10 ** self::decimals($currency));
    }

    /** Montant majeur sous forme de chaîne pour les API PSP ("12.50", "5000"). */
    public static function decimal(int $minor, string $currency = 'EUR'): string
    {
        $decimals = self::decimals($currency);

        return number_format(self::toMajor($minor, $currency), $decimals, '.', '');
    }

    /** Valeur à pré-remplir dans un champ de saisie ("12,50", "5000"). */
    public static function input(int $minor, string $currency = 'EUR'): string
    {
        return number_format(self::toMajor($minor, $currency), self::decimals($currency), ',', '');
    }

    public static function min(string $currency): int
    {
        return (int) config('payments.currencies.'.strtoupper($currency).'.min');
    }

    public static function max(string $currency): int
    {
        return (int) config('payments.currencies.'.strtoupper($currency).'.max');
    }
}
