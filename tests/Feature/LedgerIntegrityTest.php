<?php

namespace Tests\Feature;

use App\Services\Ledger\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function book(LedgerService $ledger, string $ref, int $amount = 1000): void
    {
        $ledger->post('donation.collected', 'psp:fake:clearing', 'fund:general', $amount, 'payment', $ref);
    }

    public function test_chain_is_valid_and_posting_is_idempotent(): void
    {
        $ledger = app(LedgerService::class);
        $this->book($ledger, 'p1');
        $this->book($ledger, 'p2', 2500);
        $this->book($ledger, 'p1'); // doublon ignoré

        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertTrue($ledger->verify()['ok']);
        $this->assertSame(3500, $ledger->balance('psp:fake:clearing'));
        $this->assertSame(-3500, $ledger->balance('fund:general'));
    }

    public function test_database_refuses_update_and_delete(): void
    {
        $this->book(app(LedgerService::class), 'p1');

        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->update(['amount_cents' => 1]);
    }

    public function test_delete_is_refused(): void
    {
        $this->book(app(LedgerService::class), 'p1');

        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->delete();
    }

    public function test_tampering_is_detected_even_if_triggers_are_bypassed(): void
    {
        $ledger = app(LedgerService::class);
        $this->book($ledger, 'p1');
        $this->book($ledger, 'p2');
        $this->book($ledger, 'p3');

        // Un administrateur malveillant supprime le trigger et modifie un montant.
        DB::unprepared('DROP TRIGGER ledger_no_update');
        DB::table('ledger_entries')->where('seq', 2)->update(['amount_cents' => 1]);

        $result = $ledger->verify();
        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['broken_seq']);
        $this->assertSame('columns differ from hashed payload', $result['reason']);
    }

    public function test_verify_command(): void
    {
        $this->book(app(LedgerService::class), 'p1');

        $this->artisan('ledger:verify --balance')->assertSuccessful();
    }
}
