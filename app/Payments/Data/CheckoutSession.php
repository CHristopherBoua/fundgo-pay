<?php

namespace App\Payments\Data;

final readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,
        public string $reference,
        public array $raw = [],
    ) {}
}
