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
use Illuminate\Http\Request;
use Omnipay\Mollie\Gateway as MollieGateway;

/**
 * Mollie via omnipay/mollie.
 *
 * - SEPA : mandat créé par API à partir de l'IBAN (méthode `directdebit`), puis paiements
 *   `sequenceType=recurring` (prélèvements RCUR).
 * - Carte : premier paiement hébergé `sequenceType=first` (3DS) qui crée un mandat
 *   `creditcard`, puis paiements `recurring` (MIT).
 * - Webhook : Mollie n'envoie que l'id ; on relit TOUJOURS la transaction par API
 *   (le webhook n'est jamais cru sur parole).
 */
class MollieDriver extends AbstractOmnipayDriver
{
    public function supports(PaymentMethodType $type): bool
    {
        return true;
    }

    /** @return MollieGateway */
    protected function gateway(): MollieGateway
    {
        return parent::gateway();
    }

    public function createCustomer(User $user): string
    {
        $response = $this->sendOrFail($this->gateway()->createCustomer([
            'description' => $user->name,
            'email' => $user->email,
            'locale' => 'fr_FR',
            'metadata' => ['user_id' => (string) $user->id],
        ]), 'createCustomer');

        return $response->getCustomerReference();
    }

    public function createSepaMandate(User $user, string $customerRef, SepaMandateRequest $request): MandateResult
    {
        $response = $this->sendOrFail($this->gateway()->createCustomerMandate([
            'customerReference' => $customerRef,
            'method' => 'directdebit',
            'consumerName' => $request->holderName,
            'consumerAccount' => $request->iban,
            'consumerBic' => $request->bic,
            'signatureDate' => $request->signedAt->format('Y-m-d'),
            'mandateReference' => $request->rum,
        ]), 'createCustomerMandate');

        $data = (array) $response->getData();

        return new MandateResult(
            status: ($data['status'] ?? null) === 'invalid' ? PaymentMethodStatus::Failed
                : (($data['status'] ?? null) === 'pending' ? PaymentMethodStatus::Pending : PaymentMethodStatus::Active),
            mandateRef: $response->getMandateId(),
            token: null,
            proof: array_intersect_key($data, array_flip(['id', 'status', 'method', 'signatureDate', 'mandateReference', 'createdAt'])),
        );
    }

    public function startHostedCheckout(Payment $payment, string $customerRef, string $returnUrl): CheckoutSession
    {
        $response = $this->sendOrFail($this->gateway()->purchase(array_filter([
            'amount' => $this->decimal($payment->amount_cents),
            'currency' => $payment->currency,
            'description' => $payment->description(),
            'returnUrl' => $returnUrl,
            'notifyUrl' => $this->webhookUrl(),
            'transactionId' => $payment->id,
            'metadata' => ['payment_id' => $payment->id],
            'customerReference' => $customerRef,
            'sequenceType' => $payment->sequence_type === SequenceType::First ? 'first' : 'oneoff',
            'paymentMethod' => $payment->method_type === PaymentMethodType::Card ? ($this->config['card_method'] ?? 'creditcard') : null,
            'locale' => 'fr_FR',
        ], fn ($v) => $v !== null)), 'purchase(first)');

        if (! $response->isRedirect()) {
            throw new PaymentGatewayException('[mollie] checkout did not return a redirect');
        }

        return new CheckoutSession($response->getRedirectUrl(), $response->getTransactionReference(), (array) $response->getData());
    }

    public function chargeOffSession(Payment $payment, PaymentMethod $method): ChargeResult
    {
        $response = $this->send($this->gateway()->purchase(array_filter([
            'amount' => $this->decimal($payment->amount_cents),
            'currency' => $payment->currency,
            'description' => $payment->description(),
            'returnUrl' => url('/'), // exigé par omnipay, ignoré par Mollie pour un paiement récurrent
            'notifyUrl' => $this->webhookUrl(),
            'transactionId' => $payment->id,
            'metadata' => ['payment_id' => $payment->id],
            'customerReference' => $method->gateway_customer_ref,
            'mandateId' => $method->gateway_mandate_ref,
            'sequenceType' => 'recurring',
        ], fn ($v) => $v !== null)), 'purchase(recurring)');

        $data = (array) $response->getData();

        if (! isset($data['id'])) {
            // Refus synchrone (mandat invalide, montant…) : statut 4xx de l'API Mollie.
            return ChargeResult::failed(
                isset($data['field']) ? 'invalid_'.$data['field'] : 'mandate_invalid',
                (string) ($data['detail'] ?? $response->getMessage() ?? 'Mollie a refusé le paiement'),
                raw: $data,
            );
        }

        return $this->mapTransaction($data);
    }

    public function fetchPayment(Payment $payment): ChargeResult
    {
        return $this->mapTransaction($this->fetchTransactionData((string) $payment->gateway_reference));
    }

    public function refund(Payment $payment, ?int $amountCents = null): ChargeResult
    {
        $response = $this->sendOrFail($this->gateway()->refund([
            'transactionReference' => $payment->gateway_reference,
            'amount' => $this->decimal($amountCents ?? $payment->amount_cents),
            'currency' => $payment->currency,
        ]), 'refund');

        return new ChargeResult(PaymentStatus::Refunded, $payment->gateway_reference, raw: (array) $response->getData());
    }

    public function revokeMandate(PaymentMethod $method): void
    {
        if (! $method->gateway_customer_ref || ! $method->gateway_mandate_ref) {
            return;
        }

        $this->sendOrFail($this->gateway()->revokeCustomerMandate([
            'customerReference' => $method->gateway_customer_ref,
            'mandateId' => $method->gateway_mandate_ref,
        ]), 'revokeCustomerMandate');
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $id = (string) $request->input('id');

        if (! preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
            throw new InvalidWebhookSignature('Invalid Mollie webhook payload');
        }

        // Source de vérité : l'API, pas le corps du webhook.
        $data = $this->fetchTransactionData($id);
        $result = $this->mapTransaction($data);

        return new WebhookEvent(
            eventId: $id.':'.$result->status->value,
            type: 'payment.'.$result->status->value,
            gatewayReference: $id,
            paymentId: $data['metadata']['payment_id'] ?? $data['metadata']['transactionId'] ?? null,
            result: $result,
            payload: $data,
        );
    }

    /** @throws PaymentGatewayException */
    private function fetchTransactionData(string $reference): array
    {
        $response = $this->send($this->gateway()->fetchTransaction(['transactionReference' => $reference]), 'fetchTransaction');
        $data = (array) $response->getData();

        if (! isset($data['id'])) {
            throw new PaymentGatewayException('[mollie] transaction not found: '.$reference, '404', $data);
        }

        return $data;
    }

    /** Traduit une transaction Mollie en résultat normalisé. */
    public function mapTransaction(array $data): ChargeResult
    {
        $amount = (float) ($data['amount']['value'] ?? 0);
        $refunded = (float) ($data['amountRefunded']['value'] ?? 0);
        $chargedBack = (float) ($data['amountChargedBack']['value'] ?? 0);
        $details = (array) ($data['details'] ?? []);

        $status = match (true) {
            $chargedBack > 0 || ! empty($data['_links']['chargebacks']) => PaymentStatus::ChargedBack,
            $amount > 0 && $refunded >= $amount => PaymentStatus::Refunded,
            default => match ($data['status'] ?? null) {
                'paid' => PaymentStatus::Paid,
                'failed', 'expired' => PaymentStatus::Failed,
                'canceled' => PaymentStatus::Cancelled,
                default => PaymentStatus::Processing, // open | pending | authorized
            },
        };

        $failureCode = null;
        $failureMessage = null;
        if ($status === PaymentStatus::Failed) {
            $failureCode = $details['bankReasonCode'] ?? $details['failureReason'] ?? ($data['status'] === 'expired' ? 'expired' : 'failed');
            $failureMessage = $details['bankReason'] ?? $details['failureMessage'] ?? 'Paiement refusé';
        }

        $saved = null;
        if ($status === PaymentStatus::Paid && ($data['sequenceType'] ?? null) === 'first' && ! empty($data['mandateId'])) {
            $isCard = ($data['method'] ?? null) === 'creditcard';
            $saved = new SavedMethod(
                type: $isCard ? PaymentMethodType::Card : PaymentMethodType::SepaDebit,
                customerRef: $data['customerId'] ?? null,
                mandateRef: $data['mandateId'],
                cardBrand: $isCard ? ($details['cardLabel'] ?? null) : null,
                cardLast4: $isCard ? substr((string) ($details['cardNumber'] ?? ''), -4) ?: null : null,
                cardExpiry: $isCard && isset($details['cardExpiryDate']) ? date('m/Y', strtotime($details['cardExpiryDate'])) : null,
                ibanLast4: ! $isCard ? substr((string) ($details['consumerAccount'] ?? ''), -4) ?: null : null,
                holderName: $details['cardHolder'] ?? $details['consumerName'] ?? null,
                proof: ['payment' => $data['id'], 'mandate' => $data['mandateId'], 'method' => $data['method'] ?? null, 'cardSecurity' => $details['cardSecurity'] ?? null],
            );
        }

        return new ChargeResult($status, $data['id'] ?? null, $failureCode, $failureMessage, $saved, $data);
    }
}
