<?php

namespace Tests\Feature;

use App\Enums\DonationStatus;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Enums\SequenceType;
use App\Models\Campaign;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringDonation;
use App\Models\User;
use App\Notifications\DonationReceipt;
use App\Notifications\PaymentFailed;
use App\Notifications\RecurringDonationConfirmed;
use App\Notifications\SepaPrenotification;
use App\Payments\Drivers\FakeDriver;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DonationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'FR7630006000011234567890189';

    private User $user;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
        $this->user = User::factory()->create(['name' => 'Alex Martin']);
        $this->campaign = Campaign::factory()->create();
    }

    private function donate(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->post(route('donations.store'), array_merge([
            'campaign_id' => $this->campaign->id,
            'amount' => '25',
            'frequency' => 'once',
            'method' => 'sepa',
            'holder_name' => 'Alex Martin',
            'iban' => self::IBAN,
            'mandate_accepted' => '1',
            'day_of_month' => 5,
        ], $overrides));
    }

    public function test_one_off_sepa_donation_is_collected_and_booked(): void
    {
        $this->donate()->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(2500, $payment->amount_cents);
        $this->assertSame(SequenceType::OneOff, $payment->sequence_type);
        $this->assertSame(DonationStatus::Completed, $payment->donation->status);

        $method = PaymentMethod::sole();
        $this->assertSame('0189', $method->iban_last4);
        $this->assertStringStartsWith('FGP-', $method->rum);
        $this->assertSame('CORE', $method->consent['scheme']);
        $this->assertDatabaseMissing('payment_methods', ['iban_fingerprint' => self::IBAN]);
        $this->assertStringNotContainsString(self::IBAN, json_encode(PaymentMethod::sole()->getAttributes()));

        $entry = LedgerEntry::sole();
        $this->assertSame('psp:fake:clearing', $entry->debit_account);
        $this->assertSame('fund:campaign:'.$this->campaign->id, $entry->credit_account);
        $this->assertTrue(app(LedgerService::class)->verify()['ok']);
        $this->assertSame(2500, $this->campaign->raisedCents());

        Notification::assertSentTo($this->user, DonationReceipt::class);
    }

    public function test_same_iban_reuses_the_existing_mandate(): void
    {
        $this->donate();
        $this->donate(['amount' => '10']);

        $this->assertDatabaseCount('payment_methods', 1);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_invalid_iban_and_missing_mandate_consent_are_rejected(): void
    {
        $this->donate(['iban' => 'FR7630006000011234567890188'])->assertSessionHasErrors('iban');
        $this->donate(['mandate_accepted' => null])->assertSessionHasErrors('mandate_accepted');
        $this->donate(['amount' => '0,50'])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_monthly_sepa_donation_respects_prenotification_and_charges_once_per_period(): void
    {
        $this->donate(['frequency' => 'monthly', 'day_of_month' => 5])->assertRedirect(route('dashboard'));

        $rd = RecurringDonation::sole();
        $this->assertSame(RecurringStatus::Active, $rd->status);
        // aujourd'hui 15/11 + 14 jours = 29/11 => première échéance le 05/12
        $this->assertSame('2026-12-05', $rd->next_charge_on->toDateString());
        $this->assertSame('2026-12-05', $rd->prenotified_for->toDateString());
        $this->assertDatabaseCount('payments', 0);
        Notification::assertSentTo($this->user, RecurringDonationConfirmed::class);

        $this->artisan('donations:charge-due --date=2026-12-04')->assertSuccessful();
        $this->assertDatabaseCount('payments', 0);

        $this->travelTo(CarbonImmutable::parse('2026-12-05 08:00'));
        $this->artisan('donations:charge-due')->assertSuccessful();
        $this->artisan('donations:charge-due')->assertSuccessful(); // relance du cron : aucun doublon

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('2026-12-05', $payment->period->toDateString());
        $this->assertSame(SequenceType::Recurring, $payment->sequence_type);
        $this->assertSame('2027-01-05', $rd->fresh()->next_charge_on->toDateString());

        // Pré-notification de l'échéance de janvier : envoyée à J-14, une seule fois.
        $this->artisan('donations:prenotify --date=2026-12-20');
        $this->artisan('donations:prenotify --date=2026-12-23');
        $this->artisan('donations:prenotify --date=2026-12-24');
        Notification::assertSentToTimes($this->user, SepaPrenotification::class, 1);
        $this->assertSame('2027-01-05', $rd->fresh()->prenotified_for->toDateString());
    }

    public function test_monthly_card_donation_enrolls_card_via_hosted_checkout_then_charges_mit(): void
    {
        $response = $this->donate(['method' => 'card', 'frequency' => 'monthly', 'day_of_month' => 15, 'recurring_consent' => '1']);

        $payment = Payment::sole();
        $this->assertSame(SequenceType::First, $payment->sequence_type);
        $response->assertRedirectContains('/fake-gateway/checkout/'.$payment->id);
        $this->assertSame(RecurringStatus::Pending, RecurringDonation::sole()->status);

        $this->actingAs($this->user)->get(route('fake-gateway.checkout', $payment->id))->assertOk()->assertSee('Payer');
        $this->actingAs($this->user)
            ->post(route('fake-gateway.complete', $payment->id), ['decision' => 'pay', 'return' => route('checkout.return', $payment->id)])
            ->assertRedirect(route('checkout.return', $payment->id));

        $payment->refresh();
        $rd = RecurringDonation::sole();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(RecurringStatus::Active, $rd->status);
        $this->assertNotNull($rd->payment_method_id);
        $this->assertSame('4242', $rd->paymentMethod->card_last4);
        $this->assertSame('2026-12-15', $rd->next_charge_on->toDateString());

        $this->actingAs($this->user)->get(route('checkout.return', $payment->id))->assertOk()->assertSee('Merci');

        $this->artisan('donations:charge-due --date=2026-12-15');
        $mit = Payment::where('sequence_type', SequenceType::Recurring)->sole();
        $this->assertSame(PaymentStatus::Paid, $mit->status);
        $this->assertSame($rd->payment_method_id, $mit->payment_method_id);
    }

    public function test_card_consent_is_required_for_recurring(): void
    {
        $this->donate(['method' => 'card', 'frequency' => 'monthly'])->assertSessionHasErrors('recurring_consent');
    }

    public function test_declined_first_card_payment_does_not_start_recurring(): void
    {
        $this->donate(['method' => 'card', 'frequency' => 'monthly', 'recurring_consent' => '1']);
        $payment = Payment::sole();

        $this->actingAs($this->user)->post(route('fake-gateway.complete', $payment->id), ['decision' => 'decline']);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(RecurringStatus::Cancelled, RecurringDonation::sole()->status);
        $this->assertDatabaseCount('payment_methods', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_dunning_retries_then_marks_past_due(): void
    {
        $this->donate(['frequency' => 'monthly', 'holder_name' => 'FAIL Provision']);
        $rd = RecurringDonation::sole();

        $this->travelTo(CarbonImmutable::parse('2026-12-05 08:00'));
        $this->artisan('donations:charge-due');

        $first = Payment::sole();
        $this->assertSame(PaymentStatus::Failed, $first->status);
        $this->assertSame('AM04', $first->failure_code);
        $this->assertSame('2026-12-08', $first->next_retry_at->toDateString());
        $this->assertSame(RecurringStatus::Active, $rd->fresh()->status);
        Notification::assertSentTo($this->user, PaymentFailed::class, fn ($n) => $n->retryScheduled);

        $this->artisan('donations:retry-failed'); // trop tôt
        $this->assertDatabaseCount('payments', 1);

        $this->travelTo(CarbonImmutable::parse('2026-12-08 09:00'));
        $this->artisan('donations:retry-failed');
        $this->artisan('donations:retry-failed'); // idempotent
        $second = Payment::where('attempt_no', 2)->sole();
        $this->assertSame(PaymentStatus::Failed, $second->status);
        $this->assertSame('2026-12-15', $second->next_retry_at->toDateString());

        $this->travelTo(CarbonImmutable::parse('2026-12-15 09:00'));
        $this->artisan('donations:retry-failed');
        $third = Payment::where('attempt_no', 3)->sole();
        $this->assertNull($third->next_retry_at);
        $this->assertSame(RecurringStatus::PastDue, $rd->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_mandate_error_revokes_method_without_retry(): void
    {
        $this->donate(['frequency' => 'monthly', 'holder_name' => 'REVOKED Account']);
        $this->artisan('donations:charge-due --date=2026-12-05');

        $payment = Payment::sole();
        $this->assertSame('MD01', $payment->failure_code);
        $this->assertNull($payment->next_retry_at);
        $this->assertSame(PaymentMethodStatus::Revoked, PaymentMethod::sole()->status);
        $this->assertSame(RecurringStatus::PastDue, RecurringDonation::sole()->status);
    }

    public function test_webhook_is_idempotent_and_signature_is_enforced(): void
    {
        $this->donate();
        $payment = Payment::sole();

        $body = json_encode(['event_id' => 'evt_1', 'payment_id' => $payment->id, 'reference' => $payment->gateway_reference, 'status' => 'charged_back']);
        $sig = app(FakeDriver::class)->sign($body);

        $this->call('POST', '/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'bad'], $body)->assertStatus(400);

        foreach ([1, 2] as $_) {
            $this->call('POST', '/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $sig], $body)->assertOk();
        }

        $this->assertDatabaseCount('gateway_events', 1);
        $this->assertSame(PaymentStatus::ChargedBack, $payment->fresh()->status);
        $this->assertSame(['donation.collected', 'donation.charged_back'], LedgerEntry::orderBy('seq')->pluck('operation')->all());
        $this->assertSame(0, app(LedgerService::class)->balance('fund:campaign:'.$this->campaign->id));
        $this->assertSame(DonationStatus::Refunded, $payment->donation->fresh()->status);
    }

    public function test_chargeback_on_recurring_pauses_it(): void
    {
        $this->donate(['frequency' => 'monthly']);
        $this->artisan('donations:charge-due --date=2026-12-05');
        $payment = Payment::sole();

        $body = json_encode(['event_id' => 'evt_cb', 'payment_id' => $payment->id, 'status' => 'charged_back']);
        $this->call('POST', '/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => app(FakeDriver::class)->sign($body)], $body)->assertOk();

        $rd = RecurringDonation::sole();
        $this->assertSame(RecurringStatus::Paused, $rd->status);
        $this->assertSame('charged_back', $rd->status_reason);
    }

    public function test_donor_manages_recurring_donation_from_dashboard(): void
    {
        $this->donate(['frequency' => 'monthly']);
        $rd = RecurringDonation::sole();

        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertSee('Mes dons réguliers')->assertSee('25,00');

        $this->actingAs($this->user)->patch(route('recurring.amount', $rd->id), ['amount' => '40'])->assertRedirect();
        $this->assertSame(4000, $rd->fresh()->amount_cents);

        $this->actingAs($this->user)->post(route('recurring.pause', $rd->id), ['until' => '2027-03-01'])->assertRedirect();
        $this->assertSame(RecurringStatus::Paused, $rd->fresh()->status);
        $this->artisan('donations:charge-due --date=2026-12-05');
        $this->assertDatabaseCount('payments', 0);

        // reprise automatique à la date choisie
        $this->artisan('donations:charge-due --date=2027-03-01');
        $this->assertSame(RecurringStatus::Active, $rd->fresh()->status);
        $this->assertTrue($rd->fresh()->next_charge_on->gte(CarbonImmutable::parse('2027-03-15')));

        $this->actingAs($this->user)->post(route('recurring.cancel', $rd->id))->assertRedirect();
        $this->assertSame(RecurringStatus::Cancelled, $rd->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recurring.cancelled', 'entity_id' => $rd->id, 'actor' => 'user:'.$this->user->id]);
    }

    public function test_donor_cannot_touch_someone_elses_donation_or_payment(): void
    {
        $this->donate(['frequency' => 'monthly']);
        $rd = RecurringDonation::sole();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('recurring.cancel', $rd->id))->assertNotFound();
        $this->actingAs($intruder)->patch(route('recurring.amount', $rd->id), ['amount' => '1'])->assertNotFound();
        $this->actingAs($intruder)->delete(route('payment-methods.destroy', PaymentMethod::sole()->id))->assertNotFound();
        $this->donate(['method' => 'saved', 'payment_method_id' => PaymentMethod::sole()->id], $intruder)->assertSessionHasErrors('payment_method_id');

        $this->assertSame(RecurringStatus::Active, $rd->fresh()->status);
    }

    public function test_payment_method_in_use_cannot_be_removed(): void
    {
        $this->donate(['frequency' => 'monthly']);

        $this->actingAs($this->user)->delete(route('payment-methods.destroy', PaymentMethod::sole()->id))->assertSessionHasErrors('method');
        $this->assertSame(PaymentMethodStatus::Active, PaymentMethod::sole()->status);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('donations.store'), [])->assertRedirect(route('login'));
        $this->get(route('campaigns.show', $this->campaign))->assertOk()->assertSee('Créer un compte');
    }
}
