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
use App\Services\Payments\HostedCheckoutService;
use App\Services\Payments\MandateService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Orchestration d'un don :
 *
 *                 ┌ moyen enregistré / nouveau mandat SEPA ─► prélèvement immédiat (MIT / OOFF)
 *  ponctuel ──────┤
 *                 └ carte / compte Ecobank ─► page PSP (3DS) ou page OTP ─► webhook / validation
 *
 *                 ┌ moyen enregistré / mandat SEPA ─► échéancier automatique
 *  régulier ──────┼ carte, PSP avec tokenisation ─► 1er paiement hébergé (CIT) puis MIT automatiques
 *                 └ PSP sans débit automatique (Ecobank, UBA non tokenisé)
 *                       ─► échéancier « à régler par lien » : 1re échéance payée tout de suite,
 *                          les suivantes envoyées par e-mail
 */
class DonationService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly MandateService $mandates,
        private readonly ChargeService $charges,
        private readonly HostedCheckoutService $checkout,
        private readonly RecurringDonationService $recurring,
        private readonly AuditLogger $audit,
    ) {}

    public function donate(User $user, DonationRequest $request): DonationOutcome
    {
        $currency = $request->currency();
        $this->assertAmount($request->amountCents, $currency);

        if ($request->method === DonationRequest::METHOD_SEPA && $currency !== 'EUR') {
            throw new \InvalidArgumentException('Le prélèvement SEPA n\'est possible qu\'en euros.');
        }

        $method = match ($request->method) {
            DonationRequest::METHOD_SAVED => $user->paymentMethods()->active()->findOrFail($request->paymentMethodId),
            DonationRequest::METHOD_SEPA => $this->mandates->createSepaMandate(
                $user, (string) $request->holderName, (string) $request->iban, $request->bic, $request->ip, $request->userAgent,
            ),
            DonationRequest::METHOD_CARD, DonationRequest::METHOD_ECOBANK => null,
            default => throw new \InvalidArgumentException('Unknown method '.$request->method),
        };

        if ($method && ! $this->gateways->driver($method->gateway)->supportsCurrency($currency)) {
            throw new \InvalidArgumentException('Ce moyen de paiement ne permet pas de donner en '.$currency.'.');
        }

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
            'currency' => $request->currency(),
            'status' => DonationStatus::Pending,
            'message' => $request->message,
            'is_anonymous' => $request->anonymous,
        ]);
        $this->audit->log('donation.created', $donation, after: ['amount' => $request->amountCents, 'currency' => $request->currency(), 'method' => $request->method]);

        if ($method) {
            $payment = $this->newPayment($user, $request, $method->gateway, $method->type, SequenceType::OneOff, [
                'donation_id' => $donation->id, 'payment_method_id' => $method->id,
            ]);

            return new DonationOutcome(payment: $this->charges->submit($payment));
        }

        return $this->interactive($user, $request, SequenceType::OneOff, ['donation_id' => $donation->id]);
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

        $type = $request->interactiveType();
        $driver = $this->gateways->forMethod($type, $request->currency());

        if (! $driver->supportsOffSession($type)) {
            // Échéances à régler par lien : la 1re est payée tout de suite par le donateur présent.
            $rd = $this->recurring->startPayByLink($user, $request, $type);
            $installment = $this->charges->openInstallment($rd, notify: false);

            return new DonationOutcome(
                redirectUrl: $installment ? $this->checkout->start($installment) : null,
                payment: $installment,
                recurring: $rd->refresh(),
            );
        }

        // Carte enregistrable : le 1er paiement (3DS) enrôle la carte ; l'échéancier s'active au paiement.
        $rd = RecurringDonation::create([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'collection_mode' => RecurringDonation::MODE_AUTOMATIC,
            'amount_cents' => $request->amountCents,
            'currency' => $request->currency(),
            'frequency' => $request->frequency,
            'day_of_month' => $request->dayOfMonth,
            'status' => RecurringStatus::Pending,
        ]);
        $this->audit->log('recurring.created', $rd, after: ['amount' => $rd->amount_cents, 'frequency' => $rd->frequency->value, 'method' => $type->value]);

        $outcome = $this->interactive($user, $request, SequenceType::First, [
            'recurring_donation_id' => $rd->id,
            'period' => CarbonImmutable::today()->toDateString(),
        ]);

        return new DonationOutcome($outcome->redirectUrl, $outcome->payment, $rd);
    }

    /** Paiement initié par le donateur : page hébergée du PSP ou page OTP Ecobank. */
    private function interactive(User $user, DonationRequest $request, SequenceType $sequence, array $links): DonationOutcome
    {
        $type = $request->interactiveType();
        $driver = $this->gateways->forMethod($type, $request->currency());

        $payment = $this->newPayment($user, $request, $driver->name(), $type, $sequence, array_merge($links, [
            'metadata' => [
                'consent' => $sequence === SequenceType::First ? [
                    'text' => 'Autorisation de débits récurrents (MIT) sur la carte enregistrée',
                    'frequency' => $request->frequency?->value,
                    'amount_cents' => $request->amountCents,
                    'currency' => $request->currency(),
                    'accepted_at' => now()->toIso8601String(),
                    'ip' => $request->ip,
                    'user_agent' => mb_substr($request->userAgent, 0, 500),
                ] : null,
            ],
        ]));

        return new DonationOutcome(redirectUrl: $this->checkout->start($payment), payment: $payment->refresh());
    }

    private function newPayment(User $user, DonationRequest $request, string $gateway, PaymentMethodType $type, SequenceType $sequence, array $extra): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'campaign_id' => $request->campaign?->id,
            'gateway' => $gateway,
            'method_type' => $type,
            'sequence_type' => $sequence,
            'amount_cents' => $request->amountCents,
            'currency' => $request->currency(),
            'status' => PaymentStatus::Pending,
        ], $extra));
    }

    private function assertAmount(int $minor, string $currency): void
    {
        if (! Money::isSupported($currency)) {
            throw new \InvalidArgumentException('Devise non prise en charge : '.$currency);
        }
        if ($minor < Money::min($currency) || $minor > Money::max($currency)) {
            throw new \InvalidArgumentException('Montant hors limites');
        }
    }
}
