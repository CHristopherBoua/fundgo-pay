<?php

namespace Tests\Feature\Drivers;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\Drivers\MollieDriver;
use App\Payments\Exceptions\InvalidWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\FakesPspHttp;
use Tests\TestCase;

class MollieDriverTest extends TestCase
{
    use FakesPspHttp, RefreshDatabase;

    private function driver(): MollieDriver
    {
        return new MollieDriver('mollie', ['omnipay' => 'Mollie', 'options' => ['apiKey' => 'test_dummy']]);
    }

    private function payment(array $attrs = []): Payment
    {
        $user = User::factory()->create();

        return Payment::create(array_merge([
            'user_id' => $user->id, 'gateway' => 'mollie', 'method_type' => PaymentMethodType::SepaDebit,
            'sequence_type' => SequenceType::Recurring, 'amount_cents' => 2550, 'currency' => 'EUR', 'status' => PaymentStatus::Pending,
        ], $attrs));
    }

    public function test_sepa_mandate_is_created_through_the_mandates_api(): void
    {
        $this->fakePsp([[201, ['resource' => 'mandate', 'id' => 'mdt_abc', 'status' => 'valid', 'method' => 'directdebit']]]);
        $user = User::factory()->create();

        $result = $this->driver()->createSepaMandate($user, 'cst_123', new SepaMandateRequest(
            'Alex Martin', 'FR7630006000011234567890189', null, 'FGP-RUM1', CarbonImmutable::parse('2026-11-15'), '1.2.3.4', 'UA', 'a@b.c',
        ));

        $this->assertSame(PaymentMethodStatus::Active, $result->status);
        $this->assertSame('mdt_abc', $result->mandateRef);

        $req = $this->pspRequest(0);
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('/v2/customers/cst_123/mandates', (string) $req->getUri());
        $this->assertSame('Bearer test_dummy', $req->getHeaderLine('Authorization'));
        $this->assertSame([
            'method' => 'directdebit', 'consumerName' => 'Alex Martin', 'consumerAccount' => 'FR7630006000011234567890189',
            'signatureDate' => '2026-11-15', 'mandateReference' => 'FGP-RUM1',
        ], $this->pspJson(0));
    }

    public function test_recurring_charge_uses_customer_and_mandate(): void
    {
        $this->fakePsp([[201, ['id' => 'tr_1', 'status' => 'pending', 'amount' => ['value' => '25.50', 'currency' => 'EUR'], 'sequenceType' => 'recurring']]]);
        $payment = $this->payment();
        $method = new PaymentMethod(['gateway_customer_ref' => 'cst_1', 'gateway_mandate_ref' => 'mdt_1', 'type' => PaymentMethodType::SepaDebit]);

        $result = $this->driver()->chargeOffSession($payment, $method);

        $this->assertSame(PaymentStatus::Processing, $result->status);
        $this->assertSame('tr_1', $result->reference);
        $body = $this->pspJson(0);
        $this->assertEquals(['currency' => 'EUR', 'value' => '25.50'], $body['amount']);
        $this->assertSame('recurring', $body['sequenceType']);
        $this->assertSame('cst_1', $body['customerId']);
        $this->assertSame('mdt_1', $body['mandateId']);
        $this->assertSame($payment->id, $body['metadata']['payment_id']);
        $this->assertArrayNotHasKey('webhookUrl', $body); // APP_URL=localhost : injoignable par Mollie, donc omis
    }

    public function test_synchronous_refusal_is_a_failed_result(): void
    {
        $this->fakePsp([[422, ['status' => 422, 'title' => 'Unprocessable Entity', 'detail' => 'The mandate is invalid', 'field' => 'mandateId']]]);
        $method = new PaymentMethod(['gateway_customer_ref' => 'cst_1', 'gateway_mandate_ref' => 'mdt_x', 'type' => PaymentMethodType::SepaDebit]);

        $result = $this->driver()->chargeOffSession($this->payment(), $method);

        $this->assertSame(PaymentStatus::Failed, $result->status);
        $this->assertSame('invalid_mandateId', $result->failureCode);
    }

    public function test_transaction_mapping(): void
    {
        $d = $this->driver();

        $paidFirstCard = $d->mapTransaction([
            'id' => 'tr_9', 'status' => 'paid', 'method' => 'creditcard', 'sequenceType' => 'first',
            'customerId' => 'cst_1', 'mandateId' => 'mdt_9', 'amount' => ['value' => '10.00'],
            'details' => ['cardNumber' => '6787', 'cardLabel' => 'Mastercard', 'cardHolder' => 'A M', 'cardSecurity' => '3dsecure'],
        ]);
        $this->assertSame(PaymentStatus::Paid, $paidFirstCard->status);
        $this->assertSame(PaymentMethodType::Card, $paidFirstCard->savedMethod->type);
        $this->assertSame('mdt_9', $paidFirstCard->savedMethod->mandateRef);
        $this->assertSame('6787', $paidFirstCard->savedMethod->cardLast4);

        $failed = $d->mapTransaction(['id' => 'tr_2', 'status' => 'failed', 'details' => ['bankReasonCode' => 'AM04', 'bankReason' => 'Insufficient funds']]);
        $this->assertSame(PaymentStatus::Failed, $failed->status);
        $this->assertSame('AM04', $failed->failureCode);

        $chargeback = $d->mapTransaction(['id' => 'tr_3', 'status' => 'paid', 'amount' => ['value' => '10.00'], 'amountChargedBack' => ['value' => '10.00']]);
        $this->assertSame(PaymentStatus::ChargedBack, $chargeback->status);

        $refunded = $d->mapTransaction(['id' => 'tr_4', 'status' => 'paid', 'amount' => ['value' => '10.00'], 'amountRefunded' => ['value' => '10.00']]);
        $this->assertSame(PaymentStatus::Refunded, $refunded->status);

        $this->assertSame(PaymentStatus::Processing, $d->mapTransaction(['id' => 'tr_5', 'status' => 'open'])->status);
    }

    public function test_webhook_refetches_the_transaction(): void
    {
        $this->fakePsp([[200, ['id' => 'tr_7', 'status' => 'paid', 'amount' => ['value' => '5.00'], 'metadata' => ['payment_id' => 'p_1']]]]);

        $event = $this->driver()->parseWebhook(Request::create('/webhooks/mollie', 'POST', ['id' => 'tr_7']));

        $this->assertSame('tr_7:paid', $event->eventId);
        $this->assertSame('p_1', $event->paymentId);
        $this->assertSame(PaymentStatus::Paid, $event->result->status);
        $this->assertSame('GET', $this->pspRequest(0)->getMethod());
        $this->assertStringEndsWith('/v2/payments/tr_7', (string) $this->pspRequest(0)->getUri());
    }

    public function test_webhook_with_garbage_id_is_rejected_without_api_call(): void
    {
        $this->fakePsp([]);
        $this->expectException(InvalidWebhookSignature::class);
        $this->driver()->parseWebhook(Request::create('/webhooks/mollie', 'POST', ['id' => '../customers']));
    }
}
