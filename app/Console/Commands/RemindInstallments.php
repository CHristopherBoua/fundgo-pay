<?php

namespace App\Console\Commands;

use App\Services\Payments\ChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RemindInstallments extends Command
{
    protected $signature = 'donations:remind-installments {--date=}';

    protected $description = 'Relance les échéances « à régler par lien » non payées, puis les expire.';

    public function handle(ChargeService $charges): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $stats = $charges->remindInstallments($today);
        $this->info("Relances envoyées: {$stats['reminded']} | échéances expirées: {$stats['expired']}");

        return self::SUCCESS;
    }
}
