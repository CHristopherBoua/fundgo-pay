<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\User;
use App\Services\TaxReceipts\IssueResult;
use App\Services\TaxReceipts\TaxReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class IssueTaxReceipts extends Command
{
    protected $signature = 'tax-receipts:issue
        {year? : Année fiscale (défaut : année précédente)}
        {--user= : Limiter à un donateur (id)}
        {--dry-run : Compter sans émettre}';

    protected $description = 'Émet les reçus fiscaux annuels (cerfa 11580) pour l\'année écoulée. Idempotent.';

    public function handle(TaxReceiptService $service): int
    {
        $year = (int) ($this->argument('year') ?: CarbonImmutable::today()->subYear()->year);
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        $donors = User::query()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('payments', fn ($q) => $q->where('status', PaymentStatus::Paid)
                ->where('paid_at', '>=', $start)->where('paid_at', '<', $start->addYear()));

        if ($this->option('dry-run')) {
            $this->info("Donateurs concernés pour $year : ".$donors->count());

            return self::SUCCESS;
        }

        $counts = [];
        $missing = [];
        $donors->orderBy('id')->each(function (User $user) use ($service, $year, &$counts, &$missing) {
            $result = $service->issue($user, $year);
            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
            if ($result->status === IssueResult::MISSING_ADDRESS) {
                $missing[] = $user->id;
            }
        });

        $this->table(['Résultat', 'Donateurs'], collect($counts)->map(fn ($n, $s) => [$s, $n])->values()->all());
        if ($missing) {
            $this->warn('Adresse incomplète (reçu non émis), ids : '.implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
