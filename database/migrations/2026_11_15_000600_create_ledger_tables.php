<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grand livre en partie double, append-only, chaîné par hash.
 * - ledger_heads : une seule ligne verrouillée (SELECT … FOR UPDATE) pour sérialiser
 *   les écritures et garantir une chaîne linéaire, quel que soit le SGBD.
 * - Triggers : toute modification / suppression d'une écriture est refusée par la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_heads', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->char('last_hash', 64);
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('ledger_heads')->insert(['id' => 1, 'last_seq' => 0, 'last_hash' => str_repeat('0', 64)]);

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->unique();
            $table->timestamp('occurred_at', 6);
            $table->string('operation', 64);
            $table->string('debit_account', 128);
            $table->string('credit_account', 128);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('ref_type', 64);
            $table->string('ref_id', 64);
            $table->string('campaign_id', 26)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('gateway_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->json('payload');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ref_type', 'ref_id', 'operation']);
            $table->index('campaign_id');
            $table->index('debit_account');
            $table->index('credit_account');
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->pgsqlTriggers(),
            'mysql', 'mariadb' => $this->mysqlTriggers(),
            'sqlite' => $this->sqliteTriggers(),
            default => null,
        };
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_no_mutation ON ledger_entries; DROP FUNCTION IF EXISTS ledger_forbid_mutation();');
        }
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_heads');
    }

    private function pgsqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_forbid_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'ledger_entries is append-only (% forbidden)', TG_OP;
            END $$;
            CREATE TRIGGER ledger_no_mutation BEFORE UPDATE OR DELETE ON ledger_entries
              FOR EACH ROW EXECUTE FUNCTION ledger_forbid_mutation();
        SQL);
    }

    private function mysqlTriggers(): void
    {
        DB::unprepared("CREATE TRIGGER ledger_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only (UPDATE forbidden)'");
        DB::unprepared("CREATE TRIGGER ledger_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only (DELETE forbidden)'");
    }

    private function sqliteTriggers(): void
    {
        DB::unprepared("CREATE TRIGGER ledger_no_update BEFORE UPDATE ON ledger_entries BEGIN SELECT RAISE(ABORT, 'ledger_entries is append-only (UPDATE forbidden)'); END;");
        DB::unprepared("CREATE TRIGGER ledger_no_delete BEFORE DELETE ON ledger_entries BEGIN SELECT RAISE(ABORT, 'ledger_entries is append-only (DELETE forbidden)'); END;");
    }
};
