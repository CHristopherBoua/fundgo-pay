<?php

namespace App\Services\Donations;

use App\Enums\Frequency;
use App\Enums\PaymentMethodType;
use App\Models\Campaign;

final readonly class DonationRequest
{
    public const METHOD_SAVED = 'saved';

    public const METHOD_SEPA = 'sepa';

    public const METHOD_CARD = 'card';

    public const METHOD_ECOBANK = 'ecobank';

    public function __construct(
        public ?Campaign $campaign,
        public int $amountCents,          // unités mineures de $currency
        public ?Frequency $frequency,     // null = don ponctuel
        public string $method,            // saved | sepa | card | ecobank
        public ?string $paymentMethodId = null,
        public ?string $holderName = null,
        #[\SensitiveParameter] public ?string $iban = null,
        public ?string $bic = null,
        public int $dayOfMonth = 5,
        public ?string $message = null,
        public bool $anonymous = false,
        public string $ip = '0.0.0.0',
        public string $userAgent = '',
        public ?string $currency = null,
    ) {}

    public function isRecurring(): bool
    {
        return $this->frequency !== null;
    }

    public function currency(): string
    {
        return strtoupper($this->currency ?? $this->campaign?->currency ?? config('payments.currency'));
    }

    /** Type de moyen pour les paiements initiés par le donateur (page hébergée / OTP). */
    public function interactiveType(): ?PaymentMethodType
    {
        return match ($this->method) {
            self::METHOD_CARD => PaymentMethodType::Card,
            self::METHOD_ECOBANK => PaymentMethodType::EcobankAccount,
            default => null,
        };
    }
}
