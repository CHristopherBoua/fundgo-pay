<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payments\ChargeService;
use Illuminate\Console\Command;

class RetryFailedPayments extends Command
{
    protected $signature = 'donations:retry-failed';

    protected $description = 'Relance (dunning) les prélèvements échoués dont la date de nouvelle tentative est passée.';

    public function handle(ChargeService $charges): int
    {
        $count = 0;

        Payment::query()
            ->where('status', PaymentStatus::Failed)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->each(function (Payment $p) use ($charges, &$count) {
                if ($charges->retry($p)) {
                    $count++;
                }
            });

        $this->info("Relances soumises: $count");

        return self::SUCCESS;
    }
}
