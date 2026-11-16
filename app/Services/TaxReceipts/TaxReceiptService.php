<?php

namespace App\Services\TaxReceipts;

use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\TaxReceipt;
use App\Models\User;
use App\Notifications\TaxReceiptCancelled;
use App\Notifications\TaxReceiptIssued;
use App\Services\AuditLogger;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reçus fiscaux annuels récapitulatifs (cerfa 11580*05).
 *
 * Règles :
 *  - un reçu par donateur et par année civile d'encaissement, couvrant les paiements
 *    encaissés (statut « paid ») sur des campagnes ouvrant droit à réduction d'impôt ;
 *  - numéro continu par année (AAAA-NNNNNN), attribué sous verrou : aucun trou, aucun doublon ;
 *  - instantané figé à l'émission (donateur, organisme, paiements) + empreinte SHA-256 ;
 *  - remboursement ou rejet d'un paiement couvert => le reçu est annulé et un reçu
 *    rectificatif est émis (nouveau numéro, référence au reçu remplacé).
 */
class TaxReceiptService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(User $user, int $year, ?CarbonImmutable $today = null): IssueResult
    {
        $today ??= CarbonImmutable::today();

        if (! config('tax_receipts.enabled')) {
            return new IssueResult(IssueResult::DISABLED);
        }
        if ($year >= $today->year) {
            return new IssueResult(IssueResult::YEAR_NOT_CLOSED);
        }

        return DB::transaction(fn () => $this->issueLocked($user, $year, null));
    }

    /** Paiements couverts par un reçu de l'année donnée. */
    public function eligiblePayments(User $user, int $year): Collection
    {
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        return Payment::query()
            ->with('campaign')
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::Paid)
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $start->addYear())
            ->where(fn (Builder $q) => $q->whereNull('campaign_id')
                ->orWhereHas('campaign', fn (Builder $c) => $c->where('tax_deductible', true)))
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Appelé par PaymentProcessor (dans sa transaction) quand un paiement encaissé est
     * remboursé ou rejeté : annule le reçu qui le couvrait et émet un rectificatif.
     */
    public function onPaymentReversed(Payment $payment): void
    {
        if (! $payment->paid_at) {
            return;
        }

        $receipt = TaxReceipt::query()->issued()
            ->where('user_id', $payment->user_id)
            ->where('fiscal_year', $payment->paid_at->year)
            ->lockForUpdate()
            ->get()
            ->first(fn (TaxReceipt $r) => in_array($payment->id, $r->payment_ids, true));

        if ($receipt) {
            $this->replace($receipt, 'payment_reversed:'.$payment->id);
        }
    }

    /** Annule un reçu et émet son rectificatif (si un montant éligible subsiste). */
    public function replace(TaxReceipt $receipt, string $reason): ?TaxReceipt
    {
        return DB::transaction(function () use ($receipt, $reason) {
            $locked = TaxReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isIssued()) {
                return null;
            }

            $locked->update([
                'status' => TaxReceipt::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
            ]);
            $this->audit->log('tax_receipt.cancelled', $locked, after: ['number' => $locked->number, 'reason' => $reason]);

            $result = $this->issueLocked($locked->user, $locked->fiscal_year, $locked, bypassAddressCheck: true);

            if (! $result->issued()) {
                DB::afterCommit(fn () => $locked->user->notify(new TaxReceiptCancelled($locked)));
            }

            return $result->receipt;
        });
    }

    private function issueLocked(User $user, int $year, ?TaxReceipt $replaces, bool $bypassAddressCheck = false): IssueResult
    {
        // Sérialise les émissions d'un même donateur (double clic, cron + demande manuelle).
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        $existing = TaxReceipt::query()->issued()->where(['user_id' => $user->id, 'fiscal_year' => $year])->first();
        if ($existing) {
            return new IssueResult(IssueResult::ALREADY_ISSUED, $existing);
        }

        $payments = $this->eligiblePayments($user, $year);
        if ($payments->isEmpty()) {
            return new IssueResult(IssueResult::NO_DONATIONS);
        }

        // Un rectificatif reprend l'adresse du reçu remplacé si le profil a été vidé depuis.
        $donor = $this->donorSnapshot($user, $replaces);
        if (! $bypassAddressCheck && config('tax_receipts.require_donor_address') && ! $user->hasCompleteAddress()) {
            return new IssueResult(IssueResult::MISSING_ADDRESS);
        }

        $number = $this->nextNumber($year);
        $organization = $this->organizationSnapshot();
        $lines = $payments->map(fn (Payment $p) => [
            'id' => $p->id,
            'date' => $p->paid_at->toDateString(),
            'amount_cents' => $p->amount_cents,
            'campaign' => $p->campaign?->title,
            'kind' => $p->sequence_type === SequenceType::OneOff ? 'ponctuel' : 'régulier',
        ])->values()->all();
        $amount = (int) $payments->sum('amount_cents');

        $receipt = TaxReceipt::create([
            'number' => $number,
            'user_id' => $user->id,
            'fiscal_year' => $year,
            'amount_cents' => $amount,
            'currency' => 'EUR',
            'status' => TaxReceipt::STATUS_ISSUED,
            'payment_ids' => $payments->pluck('id')->values()->all(),
            'donor' => $donor,
            'organization' => $organization,
            'payments' => $lines,
            'content_sha256' => self::fingerprint($number, $year, $amount, $donor, $organization, $lines),
            'issued_at' => now(),
            'replaces_id' => $replaces?->id,
        ]);

        $this->audit->log('tax_receipt.issued', $receipt, after: [
            'number' => $number, 'year' => $year, 'amount' => $amount, 'payments' => count($lines), 'replaces' => $replaces?->number,
        ]);

        DB::afterCommit(fn () => $user->notify(new TaxReceiptIssued($receipt)));

        return new IssueResult(IssueResult::ISSUED, $receipt);
    }

    /** Numéro continu par année, attribué sous verrou exclusif. */
    private function nextNumber(int $year): string
    {
        DB::table('tax_receipt_counters')->insertOrIgnore(['fiscal_year' => $year, 'last_number' => 0]);
        $counter = DB::table('tax_receipt_counters')->where('fiscal_year', $year)->lockForUpdate()->first();
        $next = (int) $counter->last_number + 1;
        DB::table('tax_receipt_counters')->where('fiscal_year', $year)->update(['last_number' => $next]);

        return sprintf('%d-%06d', $year, $next);
    }

    private function donorSnapshot(User $user, ?TaxReceipt $replaces): array
    {
        $fromProfile = [
            'name' => $user->name,
            'address_line1' => $user->address_line1,
            'address_line2' => $user->address_line2,
            'postal_code' => $user->postal_code,
            'city' => $user->city,
            'country' => $user->country,
        ];

        return ! $user->hasCompleteAddress() && $replaces ? $replaces->donor : $fromProfile;
    }

    private function organizationSnapshot(): array
    {
        return array_merge(config('tax_receipts.organization'), [
            'cgi_article' => config('tax_receipts.cgi_article'),
            'reduction_rate_percent' => config('tax_receipts.reduction_rate_percent'),
            'form' => config('tax_receipts.form'),
            'nature' => config('tax_receipts.nature'),
            'payment_mode' => config('tax_receipts.payment_mode'),
        ]);
    }

    public static function fingerprint(string $number, int $year, int $amount, array $donor, array $organization, array $lines): string
    {
        return hash('sha256', LedgerService::canonical(compact('number', 'year', 'amount', 'donor', 'organization', 'lines')));
    }

    /** Vérifie qu'un reçu n'a pas été altéré depuis son émission. */
    public static function verify(TaxReceipt $receipt): bool
    {
        return hash_equals($receipt->content_sha256, self::fingerprint(
            $receipt->number, $receipt->fiscal_year, $receipt->amount_cents,
            $receipt->donor, $receipt->organization, $receipt->payments,
        ));
    }
}
