<?php

namespace Tests\Feature;

use App\Enums\PaymentMethodType;
use App\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_parsing_respects_currency_exponent(): void
    {
        $this->assertSame(1250, Money::toMinor('12,50', 'EUR'));
        $this->assertSame(1250, Money::toMinor('12.5', 'USD'));
        $this->assertSame(5000, Money::toMinor('5 000', 'XOF'));   // pas de subdivision
        $this->assertSame(5000, Money::toMinor('5000', 'XAF'));
        $this->assertSame(250050, Money::toMinor('2500,50', 'NGN')); // kobo
    }

    public function test_formatting_and_api_decimals(): void
    {
        $this->assertSame('5000', Money::decimal(5000, 'XOF'));
        $this->assertSame('12.50', Money::decimal(1250, 'EUR'));
        $this->assertStringContainsString('5', Money::format(5000, 'XOF'));
        $this->assertStringNotContainsString(',00', Money::format(5000, 'XOF'));
        $this->assertStringContainsString('12,50', Money::format(1250, 'EUR'));
        $this->assertSame('12,50', Money::input(1250, 'EUR'));
        $this->assertSame('5000', Money::input(5000, 'XOF'));
    }

    public function test_methods_offered_per_currency(): void
    {
        $this->assertSame([PaymentMethodType::SepaDebit, PaymentMethodType::Card], PaymentMethodType::forCurrency('EUR'));
        $this->assertSame([PaymentMethodType::Card, PaymentMethodType::EcobankAccount], PaymentMethodType::forCurrency('XOF'));
        $this->assertFalse(Money::isSupported('GBP'));
    }
}
