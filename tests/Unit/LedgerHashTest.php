<?php

namespace Tests\Unit;

use App\Services\Ledger\LedgerService;
use PHPUnit\Framework\TestCase;

class LedgerHashTest extends TestCase
{
    public function test_canonical_serialization_is_independent_of_key_order(): void
    {
        $a = ['b' => 2, 'a' => ['y' => null, 'x' => 'é/1'], 'list' => [3, 1]];
        $b = ['list' => [3, 1], 'a' => ['x' => 'é/1', 'y' => null], 'b' => 2];

        $this->assertSame(LedgerService::canonical($a), LedgerService::canonical($b));
        $this->assertSame('{"a":{"x":"é/1","y":null},"b":2,"list":[3,1]}', LedgerService::canonical($a));
    }

    public function test_hash_depends_on_previous_hash_and_payload(): void
    {
        $payload = ['seq' => 1, 'amountCents' => 1000];
        $h1 = LedgerService::computeHash(LedgerService::GENESIS, $payload);

        $this->assertSame(64, strlen($h1));
        $this->assertSame($h1, LedgerService::computeHash(LedgerService::GENESIS, ['amountCents' => 1000, 'seq' => 1]));
        $this->assertNotSame($h1, LedgerService::computeHash(LedgerService::GENESIS, ['seq' => 1, 'amountCents' => 1001]));
        $this->assertNotSame($h1, LedgerService::computeHash($h1, $payload));
    }
}
