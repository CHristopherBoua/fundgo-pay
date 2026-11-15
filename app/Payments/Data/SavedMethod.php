<?php

namespace App\Payments\Data;

use App\Enums\PaymentMethodType;

/**
 * Moyen de paiement enrôlé lors d'un premier paiement (CIT) et réutilisable en MIT.
 */
final readonly class SavedMethod
{
    public function __construct(
        public PaymentMethodType $type,
        public ?string $customerRef,
        public ?string $mandateRef,
        public ?string $token = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?string $cardExpiry = null,
        public ?string $ibanLast4 = null,
        public ?string $holderName = null,
        public array $proof = [],
    ) {}
}
