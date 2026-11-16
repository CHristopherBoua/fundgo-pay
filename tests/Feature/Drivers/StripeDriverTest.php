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
use App\Payments\Drivers\StripeDriver;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\Omnipay\Stripe\FundgoStripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPspHttp;
use Tests\TestCase;

class StripeDriverTest extends TestCase
{
    use FakesPspHttp, RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private function driver(): StripeDriver
    {
        return new StripeDriver('stripe', [
            'omnipay' => '\\'.FundgoStripeGateway::class,
            'options' => ['apiKey' => 'sk_test_dummy'],
            'webhook_secret' => self::SECRET,
            'webhook_tolerance' => 300,
        ]);
    }

    private function payment(): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id, 'gateway' => 'stripe', 'method_type' => PaymentMethodType::SepaDebit,
            'sequence_type' => SequenceType::Recurring, 'amount_cents' => 1500, 'currency' => 'EUR', 'status' => PaymentStatus::Pending,
        ]);
    }

    public function test_sepa_mandate_creates_payment_method_then_confirms_setup_intent_with_acceptance_proof(): void
    {
        $this->fakePsp([
            [200, ['id' => 'pm_1', 'type' => 'sepa_debit', 'sepa_debit' => ['last4' => '0189', 'fingerprint' => 'fp']]],
            [200, ['id' => 'seti_1', 'status' => 'succeeded', 'mandate' => 'mandate_1']],
        ]);

        $result = $this->driver()->createSepaMandate(User::factory()->create(), 'cus_1', new SepaMandateRequest(
            'Alex', 'FR7630006000011234567890189', null, 'FGP-R1', CarbonImmutable::now(), '9.9.9.9', 'Mozilla/5.0', 'a@b.c',
        ));

        $this->assertSame(PaymentMethodStatus::Active, $result->status);
        $this->assertSame('mandate_1', $result->mandateRef);
        $this->assertSame('pm_1', $result->token);

        $this->assertSame(['type' => 'sepa_debit', 'sepa_debit' => ['iban' => 'FR7630006000011234567890189'], 'billing_details' => ['name' => 'Alex', 'email' => 'a@b.c'], 'metadata' => ['rum' => 'FGP-R1']], $this->pspForm(0));

        $si = $this->pspForm(1);
        $this->assertStringEndsWith('/v1/setup_intents', (string) $this->pspRequest(1)->getUri());
        $this->assertSame('cus_1', $si['customer']);
        $this->assertSame(['sepa_debit'], $si['payment_method_types']);
        $this->assertSame('true', $si['confirm']);
        $this->assertSame('off_session', $si['usage']);
        $this->assertSame(['type' => 'online', 'online' => ['ip_address' => '9.9.9.9', 'user_agent' => 'Mozilla/5.0']], $si['mandate_data']['customer_acceptance']);
        $this->assertSame('mandate_FGP-R1', $this->pspRequest(1)->getHeaderLine('Idempotency-Key'));
    }

    public function test_off_session_sepa_charge_is_idempotent_and_references_the_mandate(): void
    {
        $this->fakePsp([[200, ['id' => 'pi_1', 'status' => 'processing']]]);
        $payment = $this->payment();
        $method = new PaymentMethod(['type' => PaymentMethodType::SepaDebit, 'gateway_customer_ref' => 'cus_1', 'gateway_token' => 'pm_1', 'gateway_mandate_ref' => 'mandate_1']);

        $result = $this->driver()->chargeOffSession($payment, $method);

        $this->assertSame(PaymentStatus::Processing, $result->status);
        $body = $this->pspForm(0);
        $this->assertSame('1500', $body['amount']);
        $this->assertSame('eur', $body['currency']);
        $this->assertSame(['sepa_debit'], $body['payment_method_types']);
        $this->assertSame('mandate_1', $body['mandate']);
        $this->assertSame('true', $body['off_session']);
        $this->assertSame('true', $body['confirm']);
        $this->assertSame('payment_'.$payment->id, $this->pspRequest(0)->getHeaderLine('Idempotency-Key'));
        $this->assertSame('Basic '.base64_encode('sk_test_dummy:'), $this->pspRequest(0)->getHeaderLine('Authorization'));
    }

    public function test_off_session_card_decline_is_mapped(): void
    {
        $this->fakePsp([[402, ['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.', 'payment_intent' => ['id' => 'pi_9', 'status' => 'requires_payment_method'],
        ]]]]);
        $method = new PaymentMethod(['type' => PaymentMethodType::Card, 'gateway_customer_ref' => 'cus_1', 'gateway_token' => 'pm_card']);

        $result = $this->driver()->chargeOffSession($this->payment(), $method);

        $this->assertSame(PaymentStatus::Failed, $result->status);
        $this->assertSame('insufficient_funds', $result->failureCode);
        $this->assertSame('pi_9', $result->reference);
        $this->assertSame(['card'], $this->pspForm(0)['payment_method_types']);
        $this->assertArrayNotHasKey('mandate', $this->pspForm(0));
    }

    public function test_payment_intent_mapping(): void
    {
        $d = $this->driver();

        $first = $d->mapPaymentIntent([
            'id' => 'pi_1', 'status' => 'succeeded', 'customer' => 'cus_1', 'setup_future_usage' => 'off_session',
            'payment_method' => ['id' => 'pm_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030]],
            'latest_charge' => ['id' => 'ch_1', 'refunded' => false, 'disputed' => false],
        ]);
        $this->assertSame(PaymentStatus::Paid, $first->status);
        $this->assertSame('pm_1', $first->savedMethod->token);
        $this->assertSame('04/2030', $first->savedMethod->cardExpiry);

        $this->assertSame(PaymentStatus::ChargedBack, $d->mapPaymentIntent(['id' => 'pi', 'status' => 'succeeded', 'latest_charge' => ['disputed' => true]])->status);
        $this->assertSame(PaymentStatus::Refunded, $d->mapPaymentIntent(['id' => 'pi', 'status' => 'succeeded', 'latest_charge' => ['refunded' => true]])->status);

        $sca = $d->mapPaymentIntent(['id' => 'pi', 'status' => 'requires_action']);
        $this->assertSame(PaymentStatus::Failed, $sca->status);
        $this->assertSame('authentication_required', $sca->failureCode);
    }

    public function test_webhook_signature_verification(): void
    {
        $payload = '{"id":"evt_1","type":"customer.created","data":{"object":{}}}';
        $t = time();
        $valid = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, self::SECRET);

        $this->driver()->verifySignature($payload, $valid); // ne lève pas

        foreach ([
            't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'wrong'),
            't='.($t - 1000).',v1='.hash_hmac('sha256', ($t - 1000).'.'.$payload, self::SECRET), // rejeu tardif
            '',
        ] as $header) {
            try {
                $this->driver()->verifySignature($payload, $header);
                $this->fail('signature should be rejected: '.$header);
            } catch (InvalidWebhookSignature) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_webhook_endpoint_rejects_unsigned_stripe_events(): void
    {
        config(['payments.gateways.stripe.webhook_secret' => self::SECRET, 'payments.gateways.stripe.options.apiKey' => 'sk_test_dummy']);

        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"evt_x","type":"payment_intent.succeeded"}')
            ->assertStatus(400);
        $this->assertDatabaseCount('gateway_events', 0);
    }
}
