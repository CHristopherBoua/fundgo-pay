<?php

namespace App\Payments\Data;

use App\Enums\PaymentStatus;

/** Résultat normalisé d'une opération d'encaissement, quel que soit le PSP. */
final readonly class ChargeResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $reference = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?SavedMethod $savedMethod = null,
        public array $raw = [],
    ) {}

    public static function failed(string $code, string $message, ?string $reference = null, array $raw = []): self
    {
        return new self(PaymentStatus::Failed, $reference, $code, $message, null, $raw);
    }
}
