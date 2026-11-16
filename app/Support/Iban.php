<?php

namespace App\Support;

/** Validation ISO 13616 (contrôle mod 97) et helpers d'affichage. */
final class Iban
{
    /** Longueurs par pays de la zone SEPA les plus courantes. */
    private const LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24,
        'DE' => 22, 'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22,
        'GR' => 27, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21,
        'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15,
        'PL' => 28, 'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
        'VA' => 22,
    ];

    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $country = substr($iban, 0, 2);
        if (! isset(self::LENGTHS[$country]) || strlen($iban) !== self::LENGTHS[$country]) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // mod 97 par blocs (évite les entiers > PHP_INT_MAX)
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    public static function country(string $iban): string
    {
        return substr(self::normalize($iban), 0, 2);
    }

    public static function last4(string $iban): string
    {
        return substr(self::normalize($iban), -4);
    }

    /** Empreinte non réversible (détection de doublons sans stocker l'IBAN). */
    public static function fingerprint(string $iban): string
    {
        return hash_hmac('sha256', self::normalize($iban), (string) config('app.key'));
    }
}
