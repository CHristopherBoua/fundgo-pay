<?php

namespace App\Payments\Drivers;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Contracts\PaymentDriver;
use App\Payments\Data\ChargeResult;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\MandateResult;
use App\Payments\Data\SavedMethod;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PSP simulé, sans réseau : développement local, démo et tests.
 *
 * Règles de simulation :
 * - un titulaire / nom contenant « FAIL » fait échouer les prélèvements (code AM04 = provision insuffisante) ;
 * - un titulaire contenant « REVOKED » fait échouer avec MD01 (pas de mandat) ;
 * - la page de paiement hébergée locale (/fake-gateway/checkout/…) propose « Payer » ou « Refuser ».
 * Les webhooks simulés sont signés HMAC avec APP_KEY.
 */
class FakeDriver implements PaymentDriver
{
    public function __construct(
        protected readonly string $name = 'fake',
        protected readonly array $config = [],
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function supports(PaymentMethodType $type): bool
    {
        return true;
    }

    public function createCustomer(User $user): string
    {
        return 'fcus_'.Str::lower(Str::random(14));
    }

    public function createSepaMandate(User $user, string $customerRef, SepaMandateRequest $request): MandateResult
    {
        return new MandateResult(PaymentMethodStatus::Active, 'fmdt_'.Str::lower(Str::random(14)), null, [
            'simulated' => true,
            'rum' => $request->rum,
            'signed_at' => $request->signedAt->toIso8601String(),
        ]);
    }

    public function startHostedCheckout(Payment $payment, string $customerRef, string $returnUrl): CheckoutSession
    {
        $reference = 'ftr_'.Str::lower(Str::random(16));

        return new CheckoutSession(
            route('fake-gateway.checkout', ['payment' => $payment->id, 'return' => $returnUrl]),
            $reference,
        );
    }

    public function chargeOffSession(Payment $payment, PaymentMethod $method): ChargeResult
    {
        $reference = 'ftr_'.Str::lower(Str::random(16));
        $holder = Str::upper((string) $method->holder_name);

        return match (true) {
            str_contains($holder, 'REVOKED') => ChargeResult::failed('MD01', 'Mandat absent ou révoqué', $reference),
            str_contains($holder, 'FAIL') => ChargeResult::failed('AM04', 'Provision insuffisante', $reference),
            default => new ChargeResult(PaymentStatus::Paid, $reference),
        };
    }

    public function fetchPayment(Payment $payment): ChargeResult
    {
        return new ChargeResult($payment->status, $payment->gateway_reference);
    }

    public function refund(Payment $payment, ?int $amountCents = null): ChargeResult
    {
        return new ChargeResult(PaymentStatus::Refunded, $payment->gateway_reference);
    }

    public function revokeMandate(PaymentMethod $method): void {}

    public function parseWebhook(Request $request): WebhookEvent
    {
        $expected = $this->sign($request->getContent());
        if (! hash_equals($expected, (string) $request->header('X-Fake-Signature'))) {
            throw new InvalidWebhookSignature('Invalid fake gateway signature');
        }

        $data = (array) json_decode($request->getContent(), true);
        $status = PaymentStatus::tryFrom((string) ($data['status'] ?? '')) ?? throw new InvalidWebhookSignature('Unknown status');

        $saved = null;
        if ($status === PaymentStatus::Paid && ($data['sequence_type'] ?? null) === SequenceType::First->value) {
            $saved = new SavedMethod(
                type: PaymentMethodType::Card,
                customerRef: $data['customer'] ?? null,
                mandateRef: 'fmdt_'.Str::lower(Str::random(14)),
                token: 'fpm_'.Str::lower(Str::random(14)),
                cardBrand: 'visa',
                cardLast4: '4242',
                cardExpiry: now()->addYears(3)->format('m/Y'),
                holderName: $data['holder'] ?? null,
                proof: ['simulated' => true, 'three_d_secure' => 'authenticated'],
            );
        }

        $result = new ChargeResult(
            $status,
            $data['reference'] ?? null,
            $status === PaymentStatus::Failed ? ($data['failure_code'] ?? 'card_declined') : null,
            $status === PaymentStatus::Failed ? ($data['failure_message'] ?? 'Carte refusée') : null,
            $saved,
            $data,
        );

        return new WebhookEvent(
            eventId: (string) ($data['event_id'] ?? Str::uuid()),
            type: 'payment.'.$status->value,
            gatewayReference: $data['reference'] ?? null,
            paymentId: $data['payment_id'] ?? null,
            result: $result,
            payload: $data,
        );
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
