<?php

namespace App\Services\Donations;

use App\Enums\DonationStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Enums\SequenceType;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringDonation;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Services\AuditLogger;
use App\Services\Payments\ChargeService;
use App\Services\Payments\CustomerService;
use App\Services\Payments\MandateService;
use Carbon\CarbonImmutable;

/**
 * Orchestration d'un don :
 *
 *                       ┌ moyen enregistré / nouveau mandat SEPA ─► prélèvement immédiat (MIT / OOFF)
 *  don ponctuel ────────┤
 *                       └ carte ─► page hébergée PSP (3DS) ─► webhook
 *
 *                       ┌ moyen enregistré / nouveau mandat SEPA ─► échéancier actif (1re échéance planifiée)
 *  don régulier ────────┤
 *                       └ carte ─► 1er paiement hébergé (CIT, enrôlement) ─► échéancier actif, puis MIT
 */
class DonationService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly MandateService $mandates,
        private readonly CustomerService $customers,
        private readonly ChargeService $charges,
        private readonly RecurringDonationService $recurring,
        private readonly AuditLogger $audit,
    ) {}

    public function donate(User $user, DonationRequest $request): DonationOutcome
    {
        $this->assertAmount($request->amountCents);

        $method = match ($request->method) {
            DonationRequest::METHOD_SAVED => $user->paymentMethods()->active()->findOrFail($request->paymentMethodId),
            DonationRequest::METHOD_SEPA => $this->mandates->createSepaMandate(
                $user, (string) $request->holderName, (string) $request->iban, $request->bic, $request->ip, $request->userAgent,
            ),
            DonationRequest::METHOD_CARD => null,
            default => throw new \InvalidArgumentException('Unknown method '.$request->method),
        };

        return $request->isRecurring()
            ? $this->startRecurring($user, $request, $method)
            : $this->oneOff($user, $request, $method);
    }

    private function oneOff(User $user, DonationRequest $request, ?PaymentMethod $method): DonationOutcome
    {
        $donation = Donation::create([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'amount_cents' => $request->amountCents,
            'currency' => config('payments.currency'),
            'status' => DonationStatus::Pending,
            'message' => $request->message,
            'is_anonymous' => $request->anonymous,
        ]);
        $this->audit->log('donation.created', $donation, after: ['amount' => $request->amountCents, 'method' => $request->method]);

        if ($method) {
            $payment = $this->newPayment($user, $request, $method, SequenceType::OneOff, ['donation_id' => $donation->id]);

            return new DonationOutcome(payment: $this->charges->submit($payment));
        }

        return $this->hostedCheckout($user, $request, SequenceType::OneOff, ['donation_id' => $donation->id]);
    }

    private function startRecurring(User $user, DonationRequest $request, ?PaymentMethod $method): DonationOutcome
    {
        if ($request->campaign && ! $request->campaign->allows_recurring) {
            throw new \InvalidArgumentException('Cette campagne n\'accepte pas les dons réguliers.');
        }

        if ($method) {
            $rd = $this->recurring->start($user, $request, $method);
            $payment = $rd->next_charge_on?->lte(CarbonImmutable::today()) ? $this->charges->chargeDue($rd) : null;

            return new DonationOutcome(payment: $payment, recurring: $rd->refresh());
        }

        // Carte : le 1er paiement (3DS) enrôle la carte ; l'échéancier s'active au webhook « paid ».
        $rd = RecurringDonation::create([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'amount_cents' => $request->amountCents,
            'currency' => config('payments.currency'),
            'frequency' => $request->frequency,
            'day_of_month' => $request->dayOfMonth,
            'status' => RecurringStatus::Pending,
        ]);
        $this->audit->log('recurring.created', $rd, after: ['amount' => $rd->amount_cents, 'frequency' => $rd->frequency->value, 'method' => 'card']);

        $outcome = $this->hostedCheckout($user, $request, SequenceType::First, [
            'recurring_donation_id' => $rd->id,
            'period' => CarbonImmutable::today()->toDateString(),
        ]);

        return new DonationOutcome($outcome->redirectUrl, $outcome->payment, $rd);
    }

    private function hostedCheckout(User $user, DonationRequest $request, SequenceType $sequence, array $links): DonationOutcome
    {
        $driver = $this->gateways->forMethod(PaymentMethodType::Card);
        $customerRef = $this->customers->referenceFor($user, $driver);

        $payment = Payment::create(array_merge([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'gateway' => $driver->name(),
            'method_type' => PaymentMethodType::Card,
            'sequence_type' => $sequence,
            'amount_cents' => $request->amountCents,
            'currency' => config('payments.currency'),
            'status' => PaymentStatus::Pending,
            'metadata' => [
                'customer_ref' => $customerRef,
                'consent' => $sequence === SequenceType::First ? [
                    'text' => 'Autorisation de débits récurrents (MIT) sur la carte enregistrée',
                    'frequency' => $request->frequency?->value,
                    'amount_cents' => $request->amountCents,
                    'accepted_at' => now()->toIso8601String(),
                    'ip' => $request->ip,
                    'user_agent' => mb_substr($request->userAgent, 0, 500),
                ] : null,
            ],
        ], $links));

        $session = $driver->startHostedCheckout($payment, $customerRef, route('checkout.return', $payment));
        $payment->update(['gateway_reference' => $session->reference, 'submitted_at' => now()]);

        return new DonationOutcome(redirectUrl: $session->redirectUrl, payment: $payment);
    }

    private function newPayment(User $user, DonationRequest $request, PaymentMethod $method, SequenceType $sequence, array $links): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'payment_method_id' => $method->id,
            'gateway' => $method->gateway,
            'method_type' => $method->type,
            'sequence_type' => $sequence,
            'amount_cents' => $request->amountCents,
            'currency' => config('payments.currency'),
            'status' => PaymentStatus::Pending,
        ], $links));
    }

    private function assertAmount(int $cents): void
    {
        if ($cents < config('payments.min_amount_cents') || $cents > config('payments.max_amount_cents')) {
            throw new \InvalidArgumentException('Montant hors limites');
        }
    }
}
