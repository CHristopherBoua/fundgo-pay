<?php

namespace App\Services\Donations;

use App\Enums\Frequency;
use App\Enums\RecurringStatus;
use App\Models\PaymentMethod;
use App\Models\RecurringDonation;
use App\Models\User;
use App\Notifications\RecurringDonationConfirmed;
use App\Notifications\SepaPrenotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un don régulier, piloté par le donateur depuis son espace :
 * démarrer, suspendre, reprendre, modifier le montant / le jour / le moyen de paiement, arrêter.
 */
class RecurringDonationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function start(User $user, DonationRequest $request, PaymentMethod $method): RecurringDonation
    {
        $rd = RecurringDonation::create([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'payment_method_id' => $method->id,
            'amount_cents' => $request->amountCents,
            'currency' => config('payments.currency'),
            'frequency' => $request->frequency,
            'day_of_month' => $request->dayOfMonth,
            'status' => RecurringStatus::Active,
            'started_at' => now(),
            'next_charge_on' => $this->firstChargeDate($method, $request->dayOfMonth),
        ]);

        // L'e-mail de confirmation (montant, échéancier, RUM, ICS) vaut pré-notification de la 1re échéance.
        if ($method->isSepa()) {
            $rd->update(['prenotified_for' => $rd->next_charge_on]);
        }

        $this->audit->log('recurring.created', $rd, after: [
            'amount' => $rd->amount_cents, 'frequency' => $rd->frequency->value,
            'next_charge_on' => $rd->next_charge_on?->toDateString(), 'method' => $method->type->value,
        ]);
        $user->notify(new RecurringDonationConfirmed($rd));

        return $rd;
    }

    /**
     * SEPA : 1re échéance au plus tôt à J + délai de pré-notification.
     * Carte : dès aujourd'hui si le jour choisi est aujourd'hui, sinon prochaine occurrence.
     */
    public function firstChargeDate(PaymentMethod $method, int $dayOfMonth, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= CarbonImmutable::today();
        $earliest = $method->isSepa() ? $today->addDays(config('payments.sepa.prenotification_days')) : $today;

        return Frequency::firstOccurrence($earliest, $dayOfMonth);
    }

    public function pause(RecurringDonation $rd, ?CarbonImmutable $until = null): void
    {
        $this->transition($rd, 'recurring.paused', function (RecurringDonation $rd) use ($until) {
            abort_unless(in_array($rd->status, [RecurringStatus::Active, RecurringStatus::PastDue], true), 422, 'Ce don ne peut pas être mis en pause.');
            $rd->update(['status' => RecurringStatus::Paused, 'paused_until' => $until, 'status_reason' => 'donor_paused']);
        });
    }

    public function resume(RecurringDonation $rd, ?CarbonImmutable $today = null): void
    {
        $this->transition($rd, 'recurring.resumed', function (RecurringDonation $rd) use ($today) {
            abort_unless(in_array($rd->status, [RecurringStatus::Paused, RecurringStatus::PastDue], true), 422, 'Ce don n\'est pas suspendu.');
            abort_unless($rd->paymentMethod?->isActive(), 422, 'Ajoutez d\'abord un moyen de paiement valide.');

            $next = $this->firstChargeDate($rd->paymentMethod, $rd->day_of_month, $today);
            if ($rd->next_charge_on && $rd->next_charge_on->gt($next)) {
                $next = $rd->next_charge_on;
            }

            $rd->update([
                'status' => RecurringStatus::Active,
                'paused_until' => null,
                'status_reason' => null,
                'next_charge_on' => $next,
            ]);
        });
    }

    public function changeAmount(RecurringDonation $rd, int $amountCents): void
    {
        abort_if($amountCents < config('payments.min_amount_cents') || $amountCents > config('payments.max_amount_cents'), 422, 'Montant hors limites.');

        $this->transition($rd, 'recurring.amount_changed', function (RecurringDonation $rd) use ($amountCents) {
            abort_unless($rd->isEditable(), 422);

            $updates = ['amount_cents' => $amountCents];

            // SEPA : nouveau montant => nouvelle pré-notification avant la prochaine échéance.
            if ($rd->paymentMethod?->isSepa() && $rd->next_charge_on) {
                $minDate = $this->firstChargeDate($rd->paymentMethod, $rd->day_of_month);
                if ($rd->next_charge_on->lt($minDate)) {
                    $updates['next_charge_on'] = $minDate;
                }
                $updates['prenotified_for'] = null;
            }

            $rd->update($updates);
        });
    }

    public function changePaymentMethod(RecurringDonation $rd, PaymentMethod $method): void
    {
        abort_unless($method->user_id === $rd->user_id && $method->isActive(), 422, 'Moyen de paiement invalide.');

        $this->transition($rd, 'recurring.method_changed', function (RecurringDonation $rd) use ($method) {
            $updates = ['payment_method_id' => $method->id];
            if ($method->isSepa() && $rd->next_charge_on) {
                $minDate = $this->firstChargeDate($method, $rd->day_of_month);
                $updates['next_charge_on'] = $rd->next_charge_on->lt($minDate) ? $minDate : $rd->next_charge_on;
                $updates['prenotified_for'] = null;
            }
            $rd->update($updates);
        });
    }

    public function cancel(RecurringDonation $rd): void
    {
        $this->transition($rd, 'recurring.cancelled', function (RecurringDonation $rd) {
            abort_if($rd->status === RecurringStatus::Cancelled, 422, 'Déjà arrêté.');
            $rd->update(['status' => RecurringStatus::Cancelled, 'cancelled_at' => now(), 'next_charge_on' => null, 'status_reason' => 'donor_cancelled']);
        });
    }

    /** Reprise automatique des pauses arrivées à échéance. */
    public function resumeExpiredPauses(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $count = 0;

        RecurringDonation::query()
            ->where('status', RecurringStatus::Paused)
            ->where('status_reason', 'donor_paused')
            ->whereNotNull('paused_until')
            ->where('paused_until', '<=', $today->toDateString())
            ->each(function (RecurringDonation $rd) use (&$count, $today) {
                if ($rd->paymentMethod?->isActive()) {
                    $this->resume($rd, $today);
                    $count++;
                }
            });

        return $count;
    }

    /** Pré-notification SEPA à J - N pour chaque échéance pas encore notifiée. */
    public function sendPrenotifications(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $horizon = $today->addDays(config('payments.sepa.prenotification_days'));
        $count = 0;

        RecurringDonation::query()
            ->with(['user', 'paymentMethod'])
            ->where('status', RecurringStatus::Active)
            ->whereNotNull('next_charge_on')
            ->where('next_charge_on', '<=', $horizon->toDateString())
            ->where(fn ($q) => $q->whereNull('prenotified_for')->orWhereColumn('prenotified_for', '!=', 'next_charge_on'))
            ->each(function (RecurringDonation $rd) use (&$count) {
                if (! $rd->paymentMethod?->isSepa()) {
                    return;
                }
                $rd->update(['prenotified_for' => $rd->next_charge_on]);
                $rd->user->notify(new SepaPrenotification($rd));
                $count++;
            });

        return $count;
    }

    private function transition(RecurringDonation $rd, string $action, callable $change): void
    {
        DB::transaction(function () use ($rd, $action, $change) {
            $locked = RecurringDonation::query()->whereKey($rd->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->only(['status', 'amount_cents', 'next_charge_on', 'payment_method_id']);
            $change($locked);
            $this->audit->log($action, $locked, before: $this->serialize($before), after: $this->serialize($locked->only(array_keys($before))));
        });

        $rd->refresh();
    }

    private function serialize(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), $values);
    }
}
