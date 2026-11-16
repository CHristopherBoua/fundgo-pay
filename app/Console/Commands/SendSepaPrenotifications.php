<?php

namespace App\Console\Commands;

use App\Services\Donations\RecurringDonationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendSepaPrenotifications extends Command
{
    protected $signature = 'donations:prenotify {--date=}';

    protected $description = 'Envoie les pré-notifications SEPA (J - SEPA_PRENOTIFICATION_DAYS) des prochaines échéances.';

    public function handle(RecurringDonationService $recurring): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $this->info('Pré-notifications envoyées: '.$recurring->sendPrenotifications($today));

        return self::SUCCESS;
    }
}
