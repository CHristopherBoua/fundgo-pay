<?php

namespace App\Services\Donations;

use App\Enums\Frequency;
use App\Models\Campaign;

final readonly class DonationRequest
{
    public const METHOD_SAVED = 'saved';
    public const METHOD_SEPA = 'sepa';
    public const METHOD_CARD = 'card';

    public function __construct(
        public ?Campaign $campaign,
        public int $amountCents,
        public ?Frequency $frequency,     // null = don ponctuel
        public string $method,            // saved | sepa | card
        public ?string $paymentMethodId = null,
        public ?string $holderName = null,
        #[\SensitiveParameter] public ?string $iban = null,
        public ?string $bic = null,
        public int $dayOfMonth = 5,
        public ?string $message = null,
        public bool $anonymous = false,
        public string $ip = '0.0.0.0',
        public string $userAgent = '',
    ) {}

    public function isRecurring(): bool
    {
        return $this->frequency !== null;
    }
}
