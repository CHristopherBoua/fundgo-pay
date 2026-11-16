<?php

namespace App\Console\Commands;

use App\Enums\RecurringStatus;
use App\Models\RecurringDonation;
use App\Services\Donations\RecurringDonationService;
use App\Services\Payments\ChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ChargeDueDonations extends Command
{
    protected $signature = 'donations:charge-due {--date= : Date de référence (Y-m-d), défaut aujourd\'hui}';

    protected $description = 'Encaisse les échéances dues des dons réguliers (idempotent, relançable sans risque).';

    public function handle(ChargeService $charges, RecurringDonationService $recurring): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();

        $resumed = $recurring->resumeExpiredPauses($today);
        $stale = $charges->resubmitStale();

        $charged = 0;
        RecurringDonation::query()
            ->where('status', RecurringStatus::Active)
            ->whereNotNull('next_charge_on')
            ->where('next_charge_on', '<=', $today->toDateString())
            ->orderBy('next_charge_on')
            ->each(function (RecurringDonation $rd) use ($charges, $today, &$charged) {
                // Rattrapage : une échéance manquée (serveur arrêté) est encaissée une seule fois par passage.
                if ($charges->chargeDue($rd, $today)) {
                    $charged++;
                }
            });

        $this->info("Pauses reprises: $resumed | resoumis: $stale | échéances encaissées: $charged");

        return self::SUCCESS;
    }
}
