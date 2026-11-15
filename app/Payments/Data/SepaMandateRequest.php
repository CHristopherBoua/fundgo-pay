<?php

namespace App\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * Données de signature électronique du mandat. L'IBAN transite vers le PSP
 * puis est oublié : seuls les 4 derniers caractères et une empreinte sont conservés.
 */
final readonly class SepaMandateRequest
{
    public function __construct(
        public string $holderName,
        #[\SensitiveParameter] public string $iban,
        public ?string $bic,
        public string $rum,
        public CarbonImmutable $signedAt,
        public string $ip,
        public string $userAgent,
        public string $email,
    ) {}
}
