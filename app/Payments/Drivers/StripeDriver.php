<?php

namespace App\Payments\Drivers;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Data\ChargeResult;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\MandateResult;
use App\Payments\Data\SavedMethod;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\Omnipay\Stripe\FundgoStripeGateway;
use Illuminate\Http\Request;

/**
 * Stripe via omnipay/stripe (PaymentIntents) + FundgoStripeGateway.
 *
 * - Carte : Checkout hébergé (3DS) avec setup_future_usage=off_session, puis MIT hors session.
 * - SEPA Core : PaymentMethod sepa_debit + SetupIntent confirmé avec preuve d'acceptation en ligne.
 *   (Stripe ne propose pas le schéma SEPA B2B.)
 * - Webhook : signature `Stripe-Signature` vérifiée, puis PaymentIntent relu par API.
 */
class StripeDriver extends AbstractOmnipayDriver
{
    private const HANDLED_EVENTS = [
        'checkout.session.completed',
        'checkout.session.expired',
        'payment_intent.succeeded',
        'payment_intent.processing',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
        'charge.dispute.created',
    ];

    public function supports(PaymentMethodType $type): bool
    {
        return true;
    }

    /** @return FundgoStripeGateway */
    protected function gateway(): FundgoStripeGateway
    {
        return parent::gateway();
    }

    public function createCustomer(User $user): string
    {
        $response = $this->sendOrFail($this->gateway()->createCustomer([
            'email' => $user->email,
            'name' => $user->name,
            'description' => 'Donateur #'.$user->id,
            'metadata' => ['user_id' => (string) $user->id],
        ]), 'createCustomer');

        return (string) $response->getCustomerReference();
    }

    public function createSepaMandate(User $user, string $customerRef, SepaMandateRequest $request): MandateResult
    {
        $pm = $this->sendOrFail($this->gateway()->createSepaPaymentMethod(['params' => [
            'iban' => $request->iban,
            'name' => $request->holderName,
            'email' => $request->email,
            'metadata' => ['rum' => $request->rum],
        ]]), 'createSepaPaymentMethod')->getData();

        $si = $this->sendOrFail($this->gateway()->createSepaSetupIntent([
            'idempotencyKeyHeader' => 'mandate_'.$request->rum,
            'params' => [
                'customer' => $customerRef,
                'payment_method' => $pm['id'],
                'ip_address' => $request->ip,
                'user_agent' => $request->userAgent,
                'metadata' => ['rum' => $request->rum, 'user_id' => (string) $user->id],
            ],
        ]), 'createSepaSetupIntent')->getData();

        $status = match ($si['status'] ?? null) {
            'succeeded' => PaymentMethodStatus::Active,
            'processing', 'requires_confirmation' => PaymentMethodStatus::Pending,
            default => PaymentMethodStatus::Failed,
        };

        return new MandateResult(
            status: $status,
            mandateRef: (string) ($si['mandate'] ?? $si['id']),
            token: $pm['id'],
            proof: [
                'setup_intent' => $si['id'] ?? null,
                'status' => $si['status'] ?? null,
                'mandate' => $si['mandate'] ?? null,
                'iban_fingerprint' => $pm['sepa_debit']['fingerprint'] ?? null,
            ],
        );
    }

    public function startHostedCheckout(Payment $payment, string $customerRef, string $returnUrl): CheckoutSession
    {
        $data = $this->sendOrFail($this->gateway()->createCheckoutSession([
            'idempotencyKeyHeader' => 'checkout_'.$payment->id,
            'params' => [
                'customer' => $customerRef,
                'amount' => $payment->amount_cents,
                'currency' => $payment->currency,
                'description' => $payment->description(),
                'save_for_future' => $payment->sequence_type === SequenceType::First,
                'return_url' => $returnUrl,
                'metadata' => ['payment_id' => $payment->id, 'sequence_type' => $payment->sequence_type->value],
            ],
        ]), 'createCheckoutSession')->getData();

        if (empty($data['url']) || empty($data['id'])) {
            throw new PaymentGatewayException('[stripe] checkout session without url');
        }

        return new CheckoutSession($data['url'], $data['id'], $data);
    }

    public function chargeOffSession(Payment $payment, PaymentMethod $method): ChargeResult
    {
        $response = $this->send($this->gateway()->offSessionPayment([
            'idempotencyKeyHeader' => 'payment_'.$payment->id,
            'params' => [
                'amount' => $payment->amount_cents,
                'currency' => $payment->currency,
                'customer' => $method->gateway_customer_ref,
                'payment_method' => $method->gateway_token,
                'payment_method_type' => $method->type->value,
                'mandate' => $method->isSepa() && str_starts_with((string) $method->gateway_mandate_ref, 'mandate_') ? $method->gateway_mandate_ref : null,
                'description' => $payment->description(),
                'metadata' => ['payment_id' => $payment->id, 'sequence_type' => $payment->sequence_type->value],
            ],
        ]), 'offSessionPayment');

        $data = (array) $response->getData();

        if (isset($data['error'])) {
            $error = $data['error'];
            $pi = is_array($error['payment_intent'] ?? null) ? $error['payment_intent'] : [];

            return ChargeResult::failed(
                (string) ($error['decline_code'] ?? $error['code'] ?? 'declined'),
                (string) ($error['message'] ?? 'Paiement refusé'),
                $pi['id'] ?? null,
                $data,
            );
        }

        return $this->mapPaymentIntent($data);
    }

    public function fetchPayment(Payment $payment): ChargeResult
    {
        $reference = (string) $payment->gateway_reference;

        if (str_starts_with($reference, 'cs_')) {
            $session = (array) $this->sendOrFail(
                $this->gateway()->fetchCheckoutSession(['params' => ['id' => $reference]]),
                'fetchCheckoutSession',
            )->getData();

            if (($session['status'] ?? null) === 'expired') {
                return new ChargeResult(PaymentStatus::Cancelled, $reference, 'expired', 'Session de paiement expirée');
            }
            // Le PaymentIntent n'existe qu'une fois la carte saisie par le donateur.
            if (! is_string($session['payment_intent'] ?? null)) {
                return new ChargeResult(PaymentStatus::Pending, $reference);
            }
            $reference = $session['payment_intent'];
        }

        return $this->mapPaymentIntent($this->fetchPaymentIntent($reference));
    }

    public function refund(Payment $payment, ?int $amountCents = null): ChargeResult
    {
        $data = $this->sendOrFail($this->gateway()->refundPaymentIntent([
            'idempotencyKeyHeader' => 'refund_'.$payment->id.'_'.($amountCents ?? 'full'),
            'params' => [
                'payment_intent' => $payment->gateway_reference,
                'amount' => $amountCents,
                'metadata' => ['payment_id' => $payment->id],
            ],
        ]), 'refund')->getData();

        return new ChargeResult(PaymentStatus::Refunded, $payment->gateway_reference, raw: $data);
    }

    public function revokeMandate(PaymentMethod $method): void
    {
        if ($method->gateway_token) {
            $this->sendOrFail($this->gateway()->deleteCard(['paymentMethod' => $method->gateway_token]), 'detachPaymentMethod');
        }
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $this->verifySignature($request->getContent(), (string) $request->header('Stripe-Signature'));

        $event = json_decode($request->getContent(), true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new InvalidWebhookSignature('Malformed Stripe event');
        }

        $object = (array) ($event['data']['object'] ?? []);
        $paymentId = $object['metadata']['payment_id'] ?? $object['client_reference_id'] ?? null;

        if (! in_array($event['type'], self::HANDLED_EVENTS, true)) {
            return new WebhookEvent($event['id'], $event['type'], null, $paymentId, null, $event);
        }

        $piId = match (true) {
            ($object['object'] ?? null) === 'payment_intent' => $object['id'],
            default => is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null,
        };

        if ($event['type'] === 'checkout.session.expired') {
            $result = new ChargeResult(PaymentStatus::Cancelled, $object['id'] ?? null, 'expired', 'Session de paiement expirée');
        } elseif ($piId) {
            // Payload signé, mais on relit l'état courant (les événements arrivent dans le désordre).
            $pi = $this->fetchPaymentIntent($piId);
            $paymentId ??= $pi['metadata']['payment_id'] ?? null;
            $result = $this->mapPaymentIntent($pi);
        } else {
            $result = null;
        }

        return new WebhookEvent($event['id'], $event['type'], $piId, $paymentId, $result, $event);
    }

    /** @throws InvalidWebhookSignature */
    public function verifySignature(string $payload, string $header): void
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '' || $header === '') {
            throw new InvalidWebhookSignature('Missing Stripe signature or secret');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== null) {
                $signatures[] = $v;
            }
        }

        $tolerance = (int) ($this->config['webhook_tolerance'] ?? 300);
        if (! $timestamp || abs(time() - $timestamp) > $tolerance) {
            throw new InvalidWebhookSignature('Stripe signature timestamp outside tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidWebhookSignature('Stripe signature mismatch');
    }

    /** @throws PaymentGatewayException */
    private function fetchPaymentIntent(string $id): array
    {
        return (array) $this->sendOrFail(
            $this->gateway()->fetchPaymentIntentExpanded(['params' => ['id' => $id]]),
            'fetchPaymentIntent',
        )->getData();
    }

    public function mapPaymentIntent(array $pi): ChargeResult
    {
        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
        $error = (array) ($pi['last_payment_error'] ?? []);

        $status = match (true) {
            ! empty($charge['disputed']) => PaymentStatus::ChargedBack,
            ! empty($charge['refunded']) => PaymentStatus::Refunded,
            default => match ($pi['status'] ?? null) {
                'succeeded' => PaymentStatus::Paid,
                'processing', 'requires_capture', 'requires_confirmation' => PaymentStatus::Processing,
                'canceled' => PaymentStatus::Cancelled,
                // Hors session : une demande d'authentification = échec, le donateur doit revenir.
                'requires_action' => PaymentStatus::Failed,
                'requires_payment_method' => $error ? PaymentStatus::Failed : PaymentStatus::Pending,
                default => PaymentStatus::Pending,
            },
        };

        $failureCode = null;
        $failureMessage = null;
        if ($status === PaymentStatus::Failed) {
            $failureCode = ($pi['status'] ?? null) === 'requires_action'
                ? 'authentication_required'
                : (string) ($error['decline_code'] ?? $error['code'] ?? $charge['failure_code'] ?? 'declined');
            $failureMessage = (string) ($error['message'] ?? $charge['failure_message'] ?? 'Paiement refusé');
        }

        $saved = null;
        $pm = is_array($pi['payment_method'] ?? null) ? $pi['payment_method'] : null;
        if ($status === PaymentStatus::Paid && ($pi['setup_future_usage'] ?? null) === 'off_session' && $pm) {
            $isCard = ($pm['type'] ?? null) === 'card';
            $saved = new SavedMethod(
                type: $isCard ? PaymentMethodType::Card : PaymentMethodType::SepaDebit,
                customerRef: is_string($pi['customer'] ?? null) ? $pi['customer'] : null,
                mandateRef: $charge['payment_method_details']['sepa_debit']['mandate'] ?? null,
                token: $pm['id'] ?? null,
                cardBrand: $pm['card']['brand'] ?? null,
                cardLast4: $pm['card']['last4'] ?? null,
                cardExpiry: isset($pm['card']['exp_month']) ? sprintf('%02d/%d', $pm['card']['exp_month'], $pm['card']['exp_year']) : null,
                ibanLast4: $pm['sepa_debit']['last4'] ?? null,
                holderName: $pm['billing_details']['name'] ?? null,
                proof: [
                    'payment_intent' => $pi['id'] ?? null,
                    'three_d_secure' => $charge['payment_method_details']['card']['three_d_secure'] ?? null,
                    'network_transaction_id' => $charge['payment_method_details']['card']['network_transaction_id'] ?? null,
                ],
            );
        }

        return new ChargeResult($status, $pi['id'] ?? null, $failureCode, $failureMessage, $saved, $pi);
    }
}
