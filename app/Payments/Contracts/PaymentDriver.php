<?php

namespace App\Payments\Contracts;

use App\Enums\PaymentMethodType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Data\ChargeResult;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\MandateResult;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Http\Request;

/**
 * Contrat PSP-agnostique. Le cœur (dons, échéancier, grand livre, relances)
 * ne connaît que cette interface ; chaque PSP est un adaptateur Omnipay.
 */
interface PaymentDriver
{
    public function name(): string;

    /** Le PSP sait-il traiter ce type de moyen de paiement ? */
    public function supports(PaymentMethodType $type): bool;

    /**
     * Crée (ou retrouve) le client chez le PSP.
     *
     * @throws PaymentGatewayException
     */
    public function createCustomer(User $user): string;

    /**
     * Enregistre un mandat SEPA à partir de l'IBAN saisi (jamais stocké chez nous).
     *
     * @throws PaymentGatewayException
     */
    public function createSepaMandate(User $user, string $customerRef, SepaMandateRequest $request): MandateResult;

    /**
     * Paiement initié par le donateur sur une page hébergée par le PSP (3DS / SCA).
     * Si `$payment->sequence_type === first`, le moyen de paiement est enrôlé pour les MIT.
     *
     * @throws PaymentGatewayException
     */
    public function startHostedCheckout(Payment $payment, string $customerRef, string $returnUrl): CheckoutSession;

    /**
     * Paiement initié par la plateforme (MIT carte ou prélèvement SEPA sur mandat).
     *
     * @throws PaymentGatewayException
     */
    public function chargeOffSession(Payment $payment, PaymentMethod $method): ChargeResult;

    /**
     * Statut de référence, relu chez le PSP.
     *
     * @throws PaymentGatewayException
     */
    public function fetchPayment(Payment $payment): ChargeResult;

    /** @throws PaymentGatewayException */
    public function refund(Payment $payment, ?int $amountCents = null): ChargeResult;

    /** @throws PaymentGatewayException */
    public function revokeMandate(PaymentMethod $method): void;

    /**
     * Valide l'authenticité du webhook et le traduit en événement neutre.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
