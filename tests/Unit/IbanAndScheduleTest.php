<?php

namespace Tests\Unit;

use App\Enums\Frequency;
use App\Enums\PaymentStatus;
use App\Support\Iban;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class IbanAndScheduleTest extends TestCase
{
    public function test_iban_validation(): void
    {
        $this->assertTrue(Iban::isValid('FR76 3000 6000 0112 3456 7890 189'));
        $this->assertTrue(Iban::isValid('de89370400440532013000'));
        $this->assertFalse(Iban::isValid('FR76 3000 6000 0112 3456 7890 188')); // clé fausse
        $this->assertFalse(Iban::isValid('FR76 3000'));
        $this->assertSame('0189', Iban::last4('FR76 3000 6000 0112 3456 7890 189'));
        $this->assertSame('FR', Iban::country('fr7630006000011234567890189'));
    }

    public function test_schedule_keeps_day_of_month_and_never_overflows(): void
    {
        $jan = CarbonImmutable::parse('2026-01-28');
        $this->assertSame('2026-02-28', Frequency::Monthly->next($jan, 28)->toDateString());
        $this->assertSame('2026-04-05', Frequency::Quarterly->next(CarbonImmutable::parse('2026-01-05'), 5)->toDateString());
        $this->assertSame('2027-01-05', Frequency::Yearly->next(CarbonImmutable::parse('2026-01-05'), 5)->toDateString());

        // première occurrence >= date au plus tôt
        $this->assertSame('2026-11-20', Frequency::firstOccurrence(CarbonImmutable::parse('2026-11-15'), 20)->toDateString());
        $this->assertSame('2026-12-05', Frequency::firstOccurrence(CarbonImmutable::parse('2026-11-15'), 5)->toDateString());
        $this->assertSame('2026-11-15', Frequency::firstOccurrence(CarbonImmutable::parse('2026-11-15'), 15)->toDateString());
    }

    public function test_payment_state_machine_rejects_stale_transitions(): void
    {
        $this->assertTrue(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::ChargedBack));
        $this->assertFalse(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Processing)); // webhook en retard
        $this->assertFalse(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid));
        $this->assertFalse(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::Paid));
    }
}
