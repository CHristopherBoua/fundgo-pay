<?php

namespace Tests\Feature\Drivers;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\RecurringDonation;
use App\Models\User;
use App\Payments\Drivers\UbaDriver;
use App\Payments\GatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakesPspHttp;
use Tests\TestCase;

class UbaDriverTest extends TestCase
{
    use FakesPspHttp, RefreshDatabase;

    private const TOKEN = [200, ['status' => 'SUCCESS', 'data' => ['code' => '00', 'EncryptedSecKey' => ['encryptedKey' => 'tok_123'], 'message' => 'Successful']]];

    private Campaign $campaign;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'payments.gateways.uba.options.publicKey' => 'SBTESTPUBK_x',
            'payments.gateways.uba.options.secretKey' => 'SBTESTSECK_y',
            'payments.routing_by_currency.XOF.card' => 'uba',
        ]);
        $this->campaign = Campaign::factory()->create(['currency' => 'XOF', 'country' => 'SN', 'title' => 'Puits Thiès']);
        $this->user = User::factory()->create(['name' => 'Awa Diop', 'email' => 'awa@example.test']);
    }

    private function query(string $ref, string $code = '00', string $gatewayCode = '00', array $extra = []): array
    {
        return [200, ['status' => 'SUCCESS', 'data' => ['code' => $code, 'message' => 'APPROVED', 'payments' => array_merge([
            'paymentReference' => $ref, 'gatewayCode' => $gatewayCode, 'reason' => $code === '00' ? 'Successful' : 'Not sufficient funds',
            'amount' => 5000, 'currency' => 'XOF', 'channelType' => 'Visa', 'lastFourDigits' => '1234',
        ], $extra)]]];
    }

    public function test_one_off_xof_donation_goes_through_uba_hosted_checkout(): void
    {
        $this->fakePsp([
            self::TOKEN,
            [200, ['status' => 'SUCCESS', 'data' => ['code' => '00', 'payments' => ['redirectLink' => 'https://checkout.seerbitapi.com/#/?mid=x&paymentReference=r', 'paymentStatus' => '08'], 'message' => 'Successful']]],
        ]);

        $response = $this->actingAs($this->user)->post(route('donations.store'), [
            'campaign_id' => $this->campaign->id, 'amount' => '5 000', 'frequency' => 'once', 'method' => 'card',
        ]);

        $payment = Payment::sole();
        $response->assertRedirect('https://checkout.seerbitapi.com/#/?mid=x&paymentReference=r');
        $this->assertSame('uba', $payment->gateway);
        $this->assertSame('XOF', $payment->currency);
        $this->assertSame(5000, $payment->amount_cents); // XOF : pas de subdivision

        $this->assertStringEndsWith('/api/v2/encrypt/keys', (string) $this->pspRequest(0)->getUri());
        $this->assertSame(['key' => 'SBTESTSECK_y.SBTESTPUBK_x'], $this->pspJson(0));

        $req = $this->pspRequest(1);
        $this->assertStringEndsWith('/api/v2/payments', (string) $req->getUri());
        $this->assertSame('Bearer tok_123', $req->getHeaderLine('Authorization'));
        $body = $this->pspJson(1);
        $this->assertSame('5000', $body['amount']);
        $this->assertSame('XOF', $body['currency']);
        $this->assertSame('SN', $body['country']);
        $this->assertSame($payment->id, $body['paymentReference']);
        $this->assertSame('awa@example.test', $body['email']);
        $this->assertSame(route('checkout.return', $payment), $body['callbackUrl']);
        $this->assertArrayNotHasKey('tokenize', $body);
    }

    public function test_unsigned_webhook_is_verified_by_querying_the_api(): void
    {
        $payment = Payment::create([
            'user_id' => $this->user->id, 'campaign_id' => $this->campaign->id, 'gateway' => 'uba', 'method_type' => 'card',
            'sequence_type' => 'oneoff', 'amount_cents' => 5000, 'currency' => 'XOF', 'status' => 'pending',
        ]);
        $payment->update(['gateway_reference' => $payment->id]);
        // 2 relectures : le webhook rejoué déclenche lui aussi une relecture (jamais de confiance au payload).
        $this->fakePsp([self::TOKEN, $this->query($payment->id), $this->query($payment->id)]);

        $notification = ['notificationItems' => [['notificationRequestItem' => [
            'eventType' => 'transaction', 'eventId' => 'evt_abc123', 'eventDate' => '2026-11-17 10:00:00',
            // montant falsifié dans le webhook : ignoré, seul le statut relu par l'API compte
            'data' => ['reference' => $payment->id, 'status' => 'APPROVED', 'amount' => '999999'],
        ]]]];

        $this->postJson('/webhooks/uba', $notification)->assertOk();
        $this->postJson('/webhooks/uba', $notification)->assertOk(); // rejoué : idempotent

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertStringEndsWith('/api/v3/payments/query/'.$payment->id, (string) $this->pspRequest(1)->getUri());
        $this->assertDatabaseCount('gateway_events', 1);
        $this->assertDatabaseHas('ledger_entries', ['ref_id' => $payment->id, 'amount_cents' => 5000, 'currency' => 'XOF']);
    }

    public function test_refund_and_dispute_events_are_stored_without_effect(): void
    {
        $this->fakePsp([]);
        $this->postJson('/webhooks/uba', ['notificationItems' => [['notificationRequestItem' => [
            'eventType' => 'refund', 'eventId' => 'evt_r1', 'data' => ['transactionRef' => 'ABC123', 'amount' => '10'],
        ]]]])->assertOk();

        $this->assertDatabaseHas('gateway_events', ['event_id' => 'evt_r1', 'type' => 'refund']);
        $this->assertSame([], $this->pspHistory); // aucun appel API, aucun effet comptable
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_query_mapping(): void
    {
        $d = app(GatewayManager::class)->driver('uba');
        $this->assertInstanceOf(UbaDriver::class, $d);

        $this->assertSame(PaymentStatus::Paid, $d->mapQuery($this->query('r1')[1])->status);
        $this->assertSame(PaymentStatus::Processing, $d->mapQuery($this->query('r2', 'S20', 'S20')[1])->status);
        $this->assertSame(PaymentStatus::Pending, $d->mapQuery(['status' => 'FAILED', 'data' => ['code' => 'S404']])->status);

        $failed = $d->mapQuery($this->query('r3', 'SM_51', 'SM_51')[1]);
        $this->assertSame(PaymentStatus::Failed, $failed->status);
        $this->assertSame('SM_51', $failed->failureCode);
    }

    public function test_recurring_without_hosted_tokenization_becomes_pay_by_link(): void
    {
        $this->fakePsp([
            self::TOKEN,
            [200, ['status' => 'SUCCESS', 'data' => ['code' => '00', 'payments' => ['redirectLink' => 'https://checkout.seerbitapi.com/#/1'], 'message' => 'Successful']]],
        ]);

        $this->actingAs($this->user)->post(route('donations.store'), [
            'campaign_id' => $this->campaign->id, 'amount' => '10000', 'frequency' => 'monthly', 'method' => 'card',
            'recurring_consent' => '1', 'day_of_month' => 17,
        ])->assertRedirect('https://checkout.seerbitapi.com/#/1');

        $rd = RecurringDonation::sole();
        $this->assertSame(RecurringDonation::MODE_PAY_BY_LINK, $rd->collection_mode);
        $this->assertSame(PaymentMethodType::Card, $rd->method_type);
        $this->assertSame(RecurringStatus::Active, $rd->status);
        $this->assertArrayNotHasKey('tokenize', $this->pspJson(1));
    }

    public function test_hosted_tokenization_enables_automatic_debits(): void
    {
        config(['payments.gateways.uba.hosted_tokenization' => true]);
        app()->forgetInstance(GatewayManager::class);

        $this->fakePsp([
            self::TOKEN,
            [200, ['status' => 'SUCCESS', 'data' => ['code' => '00', 'payments' => ['redirectLink' => 'https://checkout.seerbitapi.com/#/2'], 'message' => 'Successful']]],
        ]);

        $this->actingAs($this->user)->post(route('donations.store'), [
            'campaign_id' => $this->campaign->id, 'amount' => '10000', 'frequency' => 'monthly', 'method' => 'card',
            'recurring_consent' => '1', 'day_of_month' => 17,
        ]);
        $first = Payment::sole();
        $this->assertTrue($this->pspJson(1)['tokenize']);
        $this->assertSame(RecurringStatus::Pending, RecurringDonation::sole()->status);

        // Retour du donateur : statut relu, code d'autorisation enregistré.
        $this->fakePsp([$this->query($first->id, extra: ['authorizationCode' => 'AUTH-777'])]);
        $this->actingAs($this->user)->get(route('checkout.return', $first->id))->assertOk();

        $rd = RecurringDonation::sole();
        $this->assertSame(RecurringStatus::Active, $rd->status);
        $this->assertSame('AUTH-777', $rd->paymentMethod->gateway_token);

        // Échéance suivante : débit automatique via /recurring/charge.
        $this->fakePsp([[200, ['status' => 'SUCCESS', 'data' => ['code' => '00', 'payments' => ['code' => '00', 'message' => 'Successful'], 'message' => 'Successful']]]]);
        $this->artisan('donations:charge-due --date='.$rd->next_charge_on->toDateString());

        $mit = Payment::where('sequence_type', 'recurring')->sole();
        $this->assertSame(PaymentStatus::Paid, $mit->status);
        $this->assertStringEndsWith('/api/v2/recurring/charge', (string) $this->pspRequest(0)->getUri());
        $this->assertSame(['amount' => '10000', 'publicKey' => 'SBTESTPUBK_x', 'email' => 'awa@example.test',
            'authorizationCode' => 'AUTH-777', 'paymentReference' => $mit->id, 'currency' => 'XOF'], $this->pspJson(0));
    }

    public function test_sepa_is_refused_outside_eur(): void
    {
        $this->actingAs($this->user)->post(route('donations.store'), [
            'campaign_id' => $this->campaign->id, 'amount' => '5000', 'frequency' => 'once', 'method' => 'sepa',
            'iban' => 'FR7630006000011234567890189', 'holder_name' => 'Awa', 'mandate_accepted' => '1',
        ])->assertSessionHasErrors('method');
        $this->assertDatabaseCount('payments', 0);
    }
}
