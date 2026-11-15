<?php

namespace App\Payments\Data;

use App\Enums\PaymentMethodStatus;

final readonly class MandateResult
{
    public function __construct(
        public PaymentMethodStatus $status,
        public string $mandateRef,
        public ?string $token = null,
        public array $proof = [],
    ) {}
}
