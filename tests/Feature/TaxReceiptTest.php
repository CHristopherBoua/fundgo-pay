<?php

namespace Tests\Feature;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\TaxReceipt;
use App\Models\User;
use App\Notifications\TaxReceiptCancelled;
use App\Notifications\TaxReceiptIssued;
use App\Payments\Drivers\FakeDriver;
use App\Services\TaxReceipts\IssueResult;
use App\Services\TaxReceipts\TaxReceiptRenderer;
use App\Services\TaxReceipts\TaxReceiptService;
use App\Support\AmountInWords;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class TaxReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $deductible;

    private Campaign $gala;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->deductible = Campaign::factory()->create(['title' => 'École numérique']);
        $this->gala = Campaign::factory()->create(['title' => 'Gala', 'tax_deductible' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-01-15 06:00'));
    }

    private function donor(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Camille Durand', 'address_line1' => '12 rue des Lilas', 'postal_code' => '69003', 'city' => 'Lyon', 'country' => 'FR',
        ], $attrs));
    }

    private function paid(User $user, int $cents, string $paidAt, ?Campaign $campaign = null, PaymentStatus $status = PaymentStatus::Paid): Payment
    {
        return Payment::create([
            'user_id' => $user->id, 'campaign_id' => ($campaign ?? $this->deductible)->id, 'gateway' => 'fake',
            'method_type' => PaymentMethodType::SepaDebit, 'sequence_type' => SequenceType::OneOff,
            'amount_cents' => $cents, 'currency' => 'EUR', 'status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? CarbonImmutable::parse($paidAt) : null,
        ]);
    }

    public function test_annual_receipt_covers_only_eligible_payments_of_the_year(): void
    {
        $user = $this->donor();
        $a = $this->paid($user, 5000, '2025-02-05 08:00');
        $b = $this->paid($user, 2550, '2025-12-31 22:30');
        $this->paid($user, 9900, '2025-06-01', $this->gala);                       // avec contrepartie
        $this->paid($user, 7000, '2025-07-01', status: PaymentStatus::Failed);     // non encaissé
        $this->paid($user, 1000, '2024-12-31 23:59');                              // autre année
        $this->paid($user, 1000, '2026-01-02');                                    // autre année

        $this->artisan('tax-receipts:issue')->assertSuccessful(); // défaut : année précédente
        $this->artisan('tax-receipts:issue 2025')->assertSuccessful(); // relance : idempotent

        $receipt = TaxReceipt::sole();
        $this->assertSame('2025-000001', $receipt->number);
        $this->assertSame(2025, $receipt->fiscal_year);
        $this->assertSame(7550, $receipt->amount_cents);
        $this->assertSame([$a->id, $b->id], $receipt->payment_ids);
        $this->assertSame('12 rue des Lilas', $receipt->donor['address_line1']);
        $this->assertSame('200', $receipt->organization['cgi_article']);
        $this->assertSame(4983, $receipt->estimatedReductionCents()); // 66 %
        $this->assertTrue(TaxReceiptService::verify($receipt));
        Notification::assertSentToTimes($user, TaxReceiptIssued::class, 1);
    }

    public function test_numbers_are_sequential_per_year_without_gaps(): void
    {
        $users = collect(range(1, 3))->map(fn () => $this->donor());
        $users->each(fn (User $u) => $this->paid($u, 1000, '2025-05-05'));
        $this->paid($u2024 = $this->donor(), 1000, '2024-05-05');

        $this->artisan('tax-receipts:issue 2025');
        $this->artisan('tax-receipts:issue 2024');

        $this->assertSame(['2025-000001', '2025-000002', '2025-000003'], TaxReceipt::where('fiscal_year', 2025)->orderBy('number')->pluck('number')->all());
        $this->assertSame('2024-000001', TaxReceipt::where('user_id', $u2024->id)->value('number'));
    }

    public function test_receipt_requires_a_complete_address_and_can_then_be_claimed_from_the_dashboard(): void
    {
        $user = $this->donor(['address_line1' => null]);
        $this->paid($user, 3000, '2025-03-03');

        $this->artisan('tax-receipts:issue 2025')->expectsOutputToContain('Adresse incomplète')->assertSuccessful();
        $this->assertDatabaseCount('tax_receipts', 0);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('adresse postale est incomplète')->assertSee('Obtenir mon reçu 2025');

        $this->actingAs($user)->post(route('tax-receipts.store'), ['year' => 2025])->assertSessionHasErrors('year');

        $user->update(['address_line1' => '3 place Bellecour']);
        $this->actingAs($user)->post(route('tax-receipts.store'), ['year' => 2025])->assertSessionHas('status');
        $this->assertSame(3000, TaxReceipt::sole()->amount_cents);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('2025-000001')->assertDontSee('Obtenir mon reçu 2025');
    }

    public function test_current_year_cannot_be_receipted(): void
    {
        $user = $this->donor();
        $this->paid($user, 1000, '2026-01-10');

        $this->assertSame(IssueResult::YEAR_NOT_CLOSED, app(TaxReceiptService::class)->issue($user, 2026)->status);
        $this->actingAs($user)->post(route('tax-receipts.store'), ['year' => 2026])->assertSessionHasErrors('year');
        $this->assertDatabaseCount('tax_receipts', 0);
    }

    public function test_issued_receipt_is_immutable(): void
    {
        $user = $this->donor();
        $this->paid($user, 1000, '2025-03-03');
        $receipt = app(TaxReceiptService::class)->issue($user, 2025)->receipt;

        try {
            $receipt->update(['amount_cents' => 999999]);
            $this->fail('amount must not be editable');
        } catch (LogicException) {
            $this->assertSame(1000, $receipt->fresh()->amount_cents);
        }

        $this->expectException(LogicException::class);
        $receipt->delete();
    }

    public function test_refund_after_issue_cancels_and_reissues_a_corrected_receipt(): void
    {
        $user = $this->donor();
        $keep = $this->paid($user, 4000, '2025-04-04');
        $refunded = $this->paid($user, 1500, '2025-09-09');
        $original = app(TaxReceiptService::class)->issue($user, 2025)->receipt;
        $this->assertSame(5500, $original->amount_cents);

        // Rejet SEPA reçu par webhook en mars 2026, après l'émission du reçu.
        $this->travelTo(CarbonImmutable::parse('2026-03-01'));
        $body = json_encode(['event_id' => 'evt_rb', 'payment_id' => $refunded->id, 'status' => 'charged_back']);
        $this->call('POST', '/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => app(FakeDriver::class)->sign($body)], $body)->assertOk();

        $original->refresh();
        $this->assertSame(TaxReceipt::STATUS_CANCELLED, $original->status);
        $this->assertStringStartsWith('payment_reversed:', $original->cancellation_reason);

        $corrected = TaxReceipt::issued()->sole();
        $this->assertSame('2025-000002', $corrected->number);
        $this->assertSame($original->id, $corrected->replaces_id);
        $this->assertSame(4000, $corrected->amount_cents);
        $this->assertSame([$keep->id], $corrected->payment_ids);
        Notification::assertSentTo($user, TaxReceiptIssued::class, fn ($n) => $n->receipt->is($corrected));
    }

    public function test_refund_of_the_only_payment_cancels_without_replacement(): void
    {
        $user = $this->donor();
        $only = $this->paid($user, 2000, '2025-04-04');
        $original = app(TaxReceiptService::class)->issue($user, 2025)->receipt;

        $body = json_encode(['event_id' => 'evt_rf', 'payment_id' => $only->id, 'status' => 'refunded']);
        $this->call('POST', '/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => app(FakeDriver::class)->sign($body)], $body)->assertOk();

        $this->assertSame(TaxReceipt::STATUS_CANCELLED, $original->fresh()->status);
        $this->assertSame(0, TaxReceipt::issued()->count());
        Notification::assertSentTo($user, TaxReceiptCancelled::class);
    }

    public function test_pdf_download_is_restricted_to_its_owner(): void
    {
        $user = $this->donor();
        $this->paid($user, 125030, '2025-04-04');
        $receipt = app(TaxReceiptService::class)->issue($user, 2025)->receipt;

        $response = $this->actingAs($user)->get(route('tax-receipts.download', $receipt->id));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('recu-fiscal-2025-000001.pdf', $response->headers->get('Content-Disposition'));
        $this->assertNotNull($receipt->fresh()->downloaded_at);

        $this->actingAs($this->donor())->get(route('tax-receipts.download', $receipt->id))->assertNotFound();
    }

    public function test_pdf_contains_the_mandatory_mentions(): void
    {
        $user = $this->donor();
        $this->paid($user, 125030, '2025-04-04');
        $receipt = app(TaxReceiptService::class)->issue($user, 2025)->receipt;

        $html = app(TaxReceiptRenderer::class)->html($receipt);

        foreach ([
            '2025-000001', 'Camille Durand', '12 rue des Lilas', '69003 Lyon',
            '1 250,30 €', AmountInWords::euros(125030), '200 du CGI', 'Déclaration de don manuel', 'Numéraire',
            'Virement, prélèvement, carte bancaire', config('tax_receipts.organization.name'), 'Annexe', 'École numérique',
        ] as $mention) {
            $this->assertStringContainsString(e($mention), $html, "missing: $mention");
        }
        $this->assertStringNotContainsString('ANNULÉ', $html);
    }

    public function test_amount_in_words(): void
    {
        $this->assertSame('un euro', AmountInWords::euros(100));
        $this->assertSame('douze euros et cinquante centimes', AmountInWords::euros(1250));
        $this->assertStringStartsWith('mille deux cent', AmountInWords::euros(125030));
        $this->assertStringEndsWith('euros et trente centimes', AmountInWords::euros(125030));
    }
}
