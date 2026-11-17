<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\RecurringDonation;
use App\Models\User;
use App\Notifications\InstallmentDue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Don régulier sur une passerelle sans débit automatique (ici : compte Ecobank simulé
 * par la passerelle fake) : chaque échéance est envoyée au donateur sous forme de lien.
 */
class PayByLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00'));
        $this->user = User::factory()->create();
        $campaign = Campaign::factory()->create(['currency' => 'XOF']);

        $this->actingAs($this->user)->post(route('donations.store'), [
            'campaign_id' => $campaign->id, 'amount' => '10000', 'frequency' => 'monthly', 'method' => 'ecobank', 'day_of_month' => 17,
        ]);
    }

    private function pay(Payment $p, string $decision = 'pay'): void
    {
        $this->actingAs($this->user)->post(route('fake-gateway.complete', $p->id), ['decision' => $decision]);
    }

    public function test_first_installment_is_paid_immediately_then_next_ones_are_sent_by_email(): void
    {
        $rd = RecurringDonation::sole();
        $this->assertSame(RecurringDonation::MODE_PAY_BY_LINK, $rd->collection_mode);
        $first = Payment::sole();
        $this->assertSame('2026-11-17', $first->period->toDateString());
        $this->assertSame('2026-12-17', $rd->next_charge_on->toDateString());
        Notification::assertNothingSentTo($this->user, InstallmentDue::class); // donateur présent : pas d'e-mail

        $this->pay($first);
        $this->assertSame(PaymentStatus::Paid, $first->fresh()->status);

        // Échéance de décembre : lien envoyé, aucun appel PSP.
        $this->artisan('donations:charge-due --date=2026-12-17');
        $december = Payment::where('period', '2026-12-17')->sole();
        $this->assertSame(PaymentStatus::Pending, $december->status);
        $this->assertNull($december->gateway_reference);
        Notification::assertSentTo($this->user, InstallmentDue::class, fn ($n) => $n->payment->is($december) && ! $n->reminder);

        $this->actingAs($this->user)->get(route('dashboard'))->assertSee('Échéances à régler');

        // Le donateur clique sur le lien -> page de paiement du PSP.
        $this->travelTo(CarbonImmutable::parse('2026-12-18 10:00'));
        $this->actingAs($this->user)->get(route('installments.pay', $december->id))
            ->assertRedirectContains('/fake-gateway/checkout/'.$december->id);
        $this->pay($december);
        $this->assertSame(PaymentStatus::Paid, $december->fresh()->status);
        $this->actingAs($this->user)->get(route('installments.pay', $december->id))->assertRedirect(route('dashboard'));
    }

    public function test_reminders_then_expiry_then_past_due_after_two_missed_installments(): void
    {
        $this->pay(Payment::sole());

        foreach (['2026-12-17', '2027-01-17'] as $i => $due) {
            $this->artisan('donations:charge-due --date='.$due);
            $installment = Payment::where('period', $due)->sole();
            $d = CarbonImmutable::parse($due);

            $this->artisan('donations:remind-installments --date='.$d->addDays(3)->toDateString());
            $this->artisan('donations:remind-installments --date='.$d->addDays(4)->toDateString()); // pas de doublon
            $this->artisan('donations:remind-installments --date='.$d->addDays(7)->toDateString());
            $this->assertSame([3, 7], $installment->fresh()->metadata['reminders']);

            $this->artisan('donations:remind-installments --date='.$d->addDays(14)->toDateString());
            $installment->refresh();
            $this->assertSame(PaymentStatus::Failed, $installment->status);
            $this->assertSame('not_paid', $installment->failure_code);

            $this->assertSame($i === 0 ? RecurringStatus::Active : RecurringStatus::PastDue, RecurringDonation::sole()->status);
        }

        Notification::assertSentToTimes($this->user, InstallmentDue::class, 6); // 2 × (envoi + 2 relances)
        $this->assertDatabaseCount('ledger_entries', 1); // seule la 1re échéance a été encaissée
    }

    public function test_xof_campaign_form_offers_card_and_ecobank_but_not_sepa(): void
    {
        $campaign = Campaign::where('currency', 'XOF')->sole();

        $html = $this->actingAs($this->user)->get(route('campaigns.show', $campaign))->assertOk();
        $html->assertSee('Compte Ecobank')->assertSee('Carte bancaire')->assertDontSee('Prélèvement SEPA')
            ->assertSee("Montant (F\u{202F}CFA)", false)->assertSee('inputmode="numeric"', false)->assertSee('5 000');
    }

    public function test_expired_link_cannot_be_paid(): void
    {
        $this->pay(Payment::sole());
        $this->artisan('donations:charge-due --date=2026-12-17');
        $installment = Payment::where('period', '2026-12-17')->sole();

        $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00'));
        $this->actingAs($this->user)->get(route('installments.pay', $installment->id))
            ->assertRedirect(route('dashboard'))->assertSessionHasErrors('installment');
    }

    public function test_declined_installment_can_be_retried_with_a_new_attempt(): void
    {
        $this->pay(Payment::sole());
        $this->artisan('donations:charge-due --date=2026-12-17');
        $installment = Payment::where('period', '2026-12-17')->sole();

        $this->actingAs($this->user)->get(route('installments.pay', $installment->id));
        $this->pay($installment, 'decline');
        $this->assertSame(PaymentStatus::Failed, $installment->fresh()->status);

        $this->actingAs($this->user)->get(route('installments.pay', $installment->id))->assertRedirectContains('/fake-gateway/checkout/');
        $retry = Payment::where(['period' => '2026-12-17', 'attempt_no' => 2])->sole();
        $this->pay($retry);
        $this->assertSame(PaymentStatus::Paid, $retry->fresh()->status);
    }
}
