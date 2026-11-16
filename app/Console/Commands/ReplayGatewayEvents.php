<?php

namespace App\Console\Commands;

use App\Models\GatewayEvent;
use App\Services\Payments\GatewayEventIngestor;
use Illuminate\Console\Command;

class ReplayGatewayEvents extends Command
{
    protected $signature = 'payments:replay-events {ids?* : Identifiants internes des événements} {--failed : Tous les événements non traités}';

    protected $description = 'Rejoue des webhooks PSP (relit le statut de référence chez le PSP, idempotent).';

    public function handle(GatewayEventIngestor $ingestor): int
    {
        $query = GatewayEvent::query();

        if ($ids = $this->argument('ids')) {
            $query->whereIn('id', $ids);
        } elseif ($this->option('failed')) {
            $query->whereNull('processed_at')->whereNotNull('error');
        } else {
            $this->error('Précisez des ids ou --failed.');

            return self::INVALID;
        }

        $ok = 0;
        $ko = 0;
        $query->orderBy('id')->each(function (GatewayEvent $e) use ($ingestor, &$ok, &$ko) {
            $ingestor->replay($e) ? $ok++ : $ko++;
        });

        $this->info("Rejoués: $ok | en échec: $ko");

        return $ko ? self::FAILURE : self::SUCCESS;
    }
}
