<?php

namespace Tests\Feature\Drivers;

use App\Enums\PaymentStatus;
use App\Models\Campaign;
use App\Models\GatewayCustomer;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Omnipay\Ecobank\EcobankSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakesPspHttp;
use Tests\TestCase;

class EcobankDriverTest extends TestCase
{
    use FakesPspHttp, RefreshDatabase;

    private const OK = [200, ['response_code' => '000', 'response_message' => 'Success']];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'payments.routing_by_currency.XOF.ecobank_account' => 'ecobank',
            'payments.gateways.ecobank.options' => array_merge(config('payments.gateways.ecobank.options'), [
                'baseUrl' => 'https://ecobank.test', 'subscriptionKey' => 'sub-key', 'serviceCode' => 'DIRECT_DEBIT',
                'clientId' => 'CLIENT1', 'secretKey' => 'S3CR3T', 'affiliateCode' => 'ESN', 'sourceCode' => 'FUNDGO',
                'ipAddress' => '203.0.113.10', 'userType' => 'CUSTOMER',
            ]),
        ]);
    }

    public function test_signer_follows_the_documented_formulas(): void
    {
        $h = ['clientId' => 'C', 'affiliateCode' => 'ESN', 'sourceCode' => 'SRC', 'requestId' => 'R1', 'requestType' => 'T', 'ipAddress' => '1.2.3.4'];

        $token = EcobankSigner::requestToken($h, 'K');
        $this->assertSame(hash('sha512', 'CESNSRCR1T1.2.3.4K'), $token);
        $this->assertSame(
            hash('sha512', 'CESNSRCR1T1.2.3.4'.$token.'5000XOFDon testK'),
            EcobankSigner::secureHash($h, $token, ['5000', 'XOF', 'Don test'], 'K'),
        );
    }

    public function test_full_flow_link_account_then_pay_with_authenticator_otp(): void
    {
        $user = User::factory()->create(['email' => 'moussa@example.test']);
        $campaign = Campaign::factory()->create(['currency' => 'XOF', 'title' => 'Cantine']);

        $this->actingAs($user)->post(route('donations.store'), [
            'campaign_id' => $campaign->id, 'amount' => '5000', 'frequency' => 'once', 'method' => 'ecobank',
        ])->assertRedirect(route('ecobank.show', Payment::sole()->id));

        $payment = Payment::sole();
        $this->assertSame('ecobank', $payment->gateway);
        $this->actingAs($user)->get(route('ecobank.show', $payment->id))->assertOk()->assertSee('Lier votre compte Ecobank');

        $this->fakePsp([
            [200, ['access_token' => 'eco-token']],                                       // token
            [200, ['response_code' => '000', 'response_content' => ['trackingId' => 'TRK1']]], // otp/send
            self::OK,                                                                       // otp/validation
            [200, ['response_code' => '000', 'response_content' => ['profileId' => 'PRF9']]],  // createprofile
            self::OK,                                                                       // completeprofile
            [200, ['response_code' => '000', 'response_content' => ['transactionReference' => 'ECO-TX-1']]], // payment
            self::OK,                                                                       // payment/validation
        ]);

        $show = route('ecobank.show', $payment->id);
        $this->actingAs($user)->from($show)->post(route('ecobank.link.send', $payment->id), ['phone' => '+221 77 000 00 00'])->assertRedirect($show);
        $this->actingAs($user)->from($show)->post(route('ecobank.link.verify', $payment->id), ['code' => '123456'])->assertRedirect($show);
        $this->actingAs($user)->from($show)->post(route('ecobank.link.complete', $payment->id), ['code' => 'A1B2C3'])->assertRedirect($show);

        $profile = GatewayCustomer::where(['user_id' => $user->id, 'gateway' => 'ecobank'])->sole();
        $this->assertNotEmpty($profile->meta['linked_at']);
        $this->assertSame('PRF9', $profile->meta['profile_id']);

        $this->actingAs($user)->from($show)->post(route('ecobank.initiate', $payment->id))->assertRedirect($show);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertSame('ECO-TX-1', $payment->fresh()->gateway_reference);

        $this->actingAs($user)->post(route('ecobank.confirm', $payment->id), ['code' => '654321'])
            ->assertRedirect(route('checkout.return', $payment->id));
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);

        // Requêtes envoyées : chemins documentés, en-têtes APIM, jeton, signatures.
        $token = $this->pspRequest(0);
        $this->assertSame('https://ecobank.test/api/v2/token', (string) $token->getUri());
        $this->assertSame('sub-key', $token->getHeaderLine('Ocp-Apim-Subscription-Key'));
        $this->assertSame('DIRECT_DEBIT', $this->pspJson(0)['serviceCode']);

        $paths = array_map(fn ($i) => parse_url((string) $this->pspRequest($i)->getUri(), PHP_URL_PATH), range(1, 6));
        $this->assertSame([
            '/api/v2/integration/directdebit/otp/send',
            '/api/v2/integration/directdebit/otp/validation',
            '/api/v2/integration/directdebit/createprofile',
            '/api/v2/integration/directdebit/completeprofile',
            '/api/v2/integration/directdebit/payment',
            '/api/v2/integration/directdebit/payment/validation',
        ], $paths);

        $init = $this->pspJson(5);
        $this->assertSame('Bearer eco-token', $this->pspRequest(5)->getHeaderLine('Authorization'));
        $this->assertSame('5000', $init['amount']);
        $this->assertSame('XOF', $init['currency']);
        $header = array_intersect_key($init, array_flip(['clientId', 'affiliateCode', 'sourceCode', 'requestId', 'requestType', 'ipAddress']));
        $this->assertSame(EcobankSigner::requestToken($header, 'S3CR3T'), $init['requestToken']);
        $this->assertSame(EcobankSigner::secureHash($header, $init['requestToken'], [$init['amount'], $init['currency'], $init['description']], 'S3CR3T'), $init['secureHash']);

        $validation = $this->pspJson(6);
        $this->assertSame('654321', $validation['token']);
        $this->assertSame('ECO-TX-1', $validation['transactionReference']);
        $this->assertSame('CUSTOMER', $this->pspJson(2)['userType']);
    }

    public function test_rejected_otp_marks_payment_failed(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'gateway' => 'ecobank', 'method_type' => 'ecobank_account', 'sequence_type' => 'oneoff',
            'amount_cents' => 2000, 'currency' => 'XOF', 'status' => PaymentStatus::Processing, 'gateway_reference' => 'ECO-TX-2',
        ]);
        $this->fakePsp([[200, ['access_token' => 't']], [200, ['response_code' => 'E51', 'response_message' => 'Invalid OTP']]]);

        $this->actingAs($user)->post(route('ecobank.confirm', $payment->id), ['code' => '000000']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('ecobank_E51', $payment->failure_code);
    }

    public function test_other_users_cannot_drive_someone_elses_payment(): void
    {
        $owner = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $owner->id, 'gateway' => 'ecobank', 'method_type' => 'ecobank_account', 'sequence_type' => 'oneoff',
            'amount_cents' => 2000, 'currency' => 'XOF', 'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs(User::factory()->create())->get(route('ecobank.show', $payment->id))->assertNotFound();
    }
}
