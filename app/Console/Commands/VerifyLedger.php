<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Console\Command;

class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify {--balance : Affiche la balance des comptes}';

    protected $description = 'Vérifie l\'intégrité de la chaîne de hash du grand livre.';

    public function handle(LedgerService $ledger): int
    {
        $result = $ledger->verify();

        if ($this->option('balance')) {
            $this->table(['Compte', 'Débit', 'Crédit', 'Solde'], array_map(fn ($l) => [
                $l['account'], Money::format($l['debit']), Money::format($l['credit']), Money::format($l['balance']),
            ], $ledger->trialBalance()));
        }

        if (! $result['ok']) {
            $this->error("Chaîne CASSÉE à seq {$result['broken_seq']} : {$result['reason']} ({$result['entries']} écritures lues)");

            return self::FAILURE;
        }

        $this->info("Grand livre intègre : {$result['entries']} écritures.");

        return self::SUCCESS;
    }
}
