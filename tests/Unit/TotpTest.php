<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Vecteurs officiels RFC 6238 (annexe B, SHA1), tronqués à 6 chiffres. */
    public function test_rfc6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertSame('050471', Totp::code($secret, 1111111111));
        $this->assertSame('005924', Totp::code($secret, 1234567890));
        $this->assertSame('279037', Totp::code($secret, 2000000000));
    }

    public function test_verify_accepts_clock_drift_of_one_period_only(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now), timestamp: $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30), timestamp: $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + 30), timestamp: $now));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90), timestamp: $now));
        $this->assertFalse(Totp::verify($secret, 'abcdef', timestamp: $now));
        $this->assertSame(Totp::base32Decode($secret), Totp::base32Decode(strtolower(chunk_split($secret, 4, ' '))));
    }
}