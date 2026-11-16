<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\RecurringDonation;
use App\Payments\Data\ChargeResult;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Encaissements initiés par la plateforme : échéances des dons réguliers, relances,
 * dons ponctuels sur un moyen de paiement déjà enregistré.
 *
 * Garantie anti-double-débit :
 *  1. la ligne `payments` est créée (unique par don régulier / échéance / tentative)
 *     et l'échéancier avancé DANS la même transaction ;
 *  2. l'appel PSP a lieu APRÈS le commit, avec l'id du paiement comme clé d'idempotence.
 */
class ChargeService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentProcessor $processor,
    ) {}

    /** Soumet au PSP un paiement hors session (MIT / prélèvement). */
    public function submit(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending || $payment->gateway_reference) {
            return $payment;
        }

        $method = $payment->paymentMethod;
        if (! $method || ! $method->isActive()) {
            return $this->processor->apply($payment, ChargeResult::failed('mandate_invalid', 'Aucun moyen de paiement actif'));
        }

        try {
            $result = $this->gateways->driver($payment->gateway)->chargeOffSession($payment, $method);
        } catch (PaymentGatewayException $e) {
            // Issue inconnue (timeout…) : on ne conclut pas à un échec. Le paiement reste « pending »
            // et sera resoumis (même clé d'idempotence) par resubmitStale().
            Log::error('[payments] submit failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            $payment->update(['failure_message' => mb_substr($e->getMessage(), 0, 500)]);

            return $payment;
        }

        $payment->update(['submitted_at' => now()]);

        return $this->processor->apply($payment, $result);
    }

    /** Crée et soumet l'échéance due d'un don régulier. Idempotent. */
    public function chargeDue(RecurringDonation $recurring, ?CarbonImmutable $today = null): ?Payment
    {
        $today ??= CarbonImmutable::today();

        $payment = DB::transaction(function () use ($recurring, $today) {
            /** @var RecurringDonation $rd */
            $rd = RecurringDonation::query()->whereKey($recurring->getKey())->lockForUpdate()->first();

            if (! $rd || $rd->status !== RecurringStatus::Active || ! $rd->next_charge_on || $rd->next_charge_on->gt($today)) {
                return null;
            }

            $method = $rd->paymentMethod;
            $period = $rd->next_charge_on;

            $payment = Payment::firstOrCreate(
                ['recurring_donation_id' => $rd->id, 'period' => $period->toDateString(), 'attempt_no' => 1],
                [
                    'user_id' => $rd->user_id,
                    'campaign_id' => $rd->campaign_id,
                    'payment_method_id' => $method?->id,
                    'gateway' => $method?->gateway ?? config('payments.default'),
                    'method_type' => $method?->type ?? 'sepa_debit',
                    'sequence_type' => SequenceType::Recurring,
                    'amount_cents' => $rd->amount_cents,
                    'currency' => $rd->currency,
                    'status' => PaymentStatus::Pending,
                ],
            );

            $rd->update(['next_charge_on' => $rd->frequency->next($period, $rd->day_of_month)]);

            return $payment->wasRecentlyCreated ? $payment : null;
        });

        return $payment ? $this->submit($payment) : null;
    }

    /** Relance planifiée après un échec. */
    public function retry(Payment $failed): ?Payment
    {
        $retry = DB::transaction(function () use ($failed) {
            /** @var Payment $p */
            $p = Payment::query()->whereKey($failed->getKey())->lockForUpdate()->first();

            if (! $p || $p->status !== PaymentStatus::Failed || ! $p->next_retry_at || $p->next_retry_at->isFuture()) {
                return null;
            }

            $p->update(['next_retry_at' => null]); // consommé : jamais deux relances pour un même échec

            $rd = $p->recurringDonation;
            if (! $rd || ! in_array($rd->status, [RecurringStatus::Active, RecurringStatus::PastDue], true)) {
                return null;
            }

            $method = $rd->paymentMethod;

            return Payment::firstOrCreate(
                ['recurring_donation_id' => $rd->id, 'period' => $p->period->toDateString(), 'attempt_no' => $p->attempt_no + 1],
                [
                    'user_id' => $p->user_id,
                    'campaign_id' => $p->campaign_id,
                    'payment_method_id' => $method?->id,
                    'gateway' => $method?->gateway ?? $p->gateway,
                    'method_type' => $method?->type ?? $p->method_type,
                    'sequence_type' => SequenceType::Recurring,
                    'amount_cents' => $p->amount_cents,
                    'currency' => $p->currency,
                    'status' => PaymentStatus::Pending,
                    'metadata' => ['retry_of' => $p->id],
                ],
            );
        });

        return $retry ? $this->submit($retry) : null;
    }

    /** Resoumet les paiements restés « pending » sans référence PSP (crash, timeout). */
    public function resubmitStale(int $olderThanMinutes = 15): int
    {
        $count = 0;

        Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->whereNull('gateway_reference')
            ->where('sequence_type', '!=', SequenceType::First->value)
            ->whereNotNull('payment_method_id')
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->each(function (Payment $p) use (&$count) {
                $this->submit($p);
                $count++;
            });

        return $count;
    }
}
