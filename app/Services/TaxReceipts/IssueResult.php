<?php

namespace App\Services\TaxReceipts;

use App\Models\TaxReceipt;

final readonly class IssueResult
{
    public const ISSUED = 'issued';

    public const ALREADY_ISSUED = 'already_issued';

    public const NO_DONATIONS = 'no_donations';

    public const MISSING_ADDRESS = 'missing_address';

    public const YEAR_NOT_CLOSED = 'year_not_closed';

    public const DISABLED = 'disabled';

    public function __construct(
        public string $status,
        public ?TaxReceipt $receipt = null,
    ) {}

    public function issued(): bool
    {
        return $this->status === self::ISSUED;
    }

    public function message(): string
    {
        return match ($this->status) {
            self::ISSUED => 'Votre reçu fiscal n° '.$this->receipt?->number.' est disponible.',
            self::ALREADY_ISSUED => 'Un reçu a déjà été émis pour cette année.',
            self::NO_DONATIONS => 'Aucun don ouvrant droit à réduction d\'impôt sur cette année.',
            self::MISSING_ADDRESS => 'Complétez votre adresse postale : elle doit figurer sur le reçu fiscal.',
            self::YEAR_NOT_CLOSED => 'Le reçu annuel est disponible après le 31 décembre.',
            self::DISABLED => 'Les reçus fiscaux ne sont pas activés.',
        };
    }
}
