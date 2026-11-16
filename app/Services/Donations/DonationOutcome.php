<?php

namespace App\Services\Donations;

use App\Models\Payment;
use App\Models\RecurringDonation;

final readonly class DonationOutcome
{
    public function __construct(
        public ?string $redirectUrl = null,
        public ?Payment $payment = null,
        public ?RecurringDonation $recurring = null,
    ) {}
}
