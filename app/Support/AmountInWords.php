<?php

namespace App\Support;

/** Somme en toutes lettres (mention du cerfa) : 1250,30 € -> « mille deux cent cinquante euros et trente centimes ». */
final class AmountInWords
{
    public static function euros(int $cents): string
    {
        $speller = new \NumberFormatter('fr_FR', \NumberFormatter::SPELLOUT);
        $euros = intdiv($cents, 100);
        $rest = $cents % 100;

        $words = $speller->format($euros).' euro'.($euros > 1 ? 's' : '');
        if ($rest > 0) {
            $words .= ' et '.$speller->format($rest).' centime'.($rest > 1 ? 's' : '');
        }

        return $words;
    }
}
