<?php

namespace App\Services\Payments;

use App\Enums\DonationStatus;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringDonation;
use App\Notifications\DonationReceipt;
use App\Notifications\PaymentFailed;
use App\Payments\Data\ChargeResult;
use App\Services\AuditLogger;
use App\Services\Ledger\LedgerService;
use App\Services\TaxReceipts\TaxReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Machine à états des paiements. Point d'entrée UNIQUE pour appliquer un statut,
 * qu'il vienne d'une réponse synchrone du PSP, d'un webhook ou d'un rejeu.
 *
 * Idempotent : un statut déjà appliqué ou une transition invalide (webhook en retard)
 * ne produit aucun effet. Les écritures comptables sont elles aussi idempotentes.
 */
class PaymentProcessor
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
        private readonly TaxReceiptService $taxReceipts,
    ) {}

    public function apply(Payment $payment, ChargeResult $result, ?string $gatewayEventId = null, string $actor = 'system'): Payment
    {
        $notification = null;

        $payment = DB::transaction(function () use ($payment, $result, $gatewayEventId, $actor, &$notification) {
            /** @var Payment $p */
            $p = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $from = $p->status;
            $to = $result->status;

            if ($result->reference && $p->gateway_reference !== $result->reference) {
                $p->gateway_reference = $result->reference;
            }

            if ($from === $to || ! $from->canTransitionTo($to)) {
                $p->save();

                return $p;
            }

            $p->status = $to;

            $notification = match ($to) {
                PaymentStatus::Processing => $this->onProcessing($p),
                PaymentStatus::Paid => $this->onPaid($p, $result, $gatewayEventId),
                PaymentStatus::Failed => $this->onFailed($p, $result),
                PaymentStatus::Cancelled => $this->onCancelled($p),
                PaymentStatus::Refunded, PaymentStatus::ChargedBack => $this->onReversed($p, $to, $gatewayEventId),
                PaymentStatus::Pending => null,
            };

            $p->save();

            if (in_array($to, [PaymentStatus::Refunded, PaymentStatus::ChargedBack], true)) {
                $this->taxReceipts->onPaymentReversed($p);
            }

            $this->audit->log('payment.'.$to->value, $p, before: ['status' => $from->value], after: array_filter([
                'status' => $to->value,
                'reference' => $p->gateway_reference,
                'failure_code' => $p->failure_code,
                'gateway_event' => $gatewayEventId,
            ]), actor: $actor);

            return $p;
        });

        // Après commit : jamais d'e-mail pour une transaction annulée.
        if ($notification) {
            $payment->user->notify($notification);
        }

        return $payment;
    }

    private function onProcessing(Payment $p): null
    {
        $p->submitted_at ??= now();

        return null;
    }

    private function onPaid(Payment $p, ChargeResult $result, ?string $eventId): DonationReceipt
    {
        $p->paid_at = now();
        $p->failure_code = null;
        $p->failure_message = null;
        $p->next_retry_at = null;

        // Encaissement : l'argent est chez le PSP (compte de transit), affecté au fonds de la campagne.
        $this->ledger->post(
            operation: 'donation.collected',
            debitAccount: $p->clearingAccount(),
            creditAccount: $p->fundAccount(),
            amountCents: $p->amount_cents,
            refType: 'payment',
            refId: $p->id,
            currency: $p->currency,
            campaignId: $p->campaign_id,
            userId: $p->user_id,
            gatewayEventId: $eventId,
            metadata: ['gateway' => $p->gateway, 'reference' => $p->gateway_reference, 'sequence' => $p->sequence_type->value],
        );

        if ($p->donation) {
            $p->donation->update(['status' => DonationStatus::Completed, 'completed_at' => now()]);
        }

        $recurring = $p->recurringDonation;

        if ($p->sequence_type === SequenceType::First && $result->savedMethod) {
            $method = $this->enrollMethod($p, $result);
            $p->payment_method_id = $method->id;

            if ($recurring && $recurring->status === RecurringStatus::Pending) {
                $start = $p->period ?? CarbonImmutable::today();
                $recurring->update([
                    'payment_method_id' => $method->id,
                    'status' => RecurringStatus::Active,
                    'started_at' => now(),
                    'next_charge_on' => $recurring->frequency->next($start, $recurring->day_of_month),
                ]);
            }
        } elseif ($recurring && $recurring->status === RecurringStatus::PastDue) {
            // Régularisation après relance : le don régulier repart.
            $recurring->update(['status' => RecurringStatus::Active, 'status_reason' => null]);
        }

        return new DonationReceipt($p);
    }

    private function onFailed(Payment $p, ChargeResult $result): PaymentFailed
    {
        $p->failed_at = now();
        $p->failure_code = $result->failureCode ?? 'failed';
        $p->failure_message = mb_substr((string) ($result->failureMessage ?? 'Paiement refusé'), 0, 500);

        if ($p->donation) {
            $p->donation->update(['status' => DonationStatus::Failed]);
        }

        $recurring = $p->recurringDonation;
        $code = $p->failure_code;

        if ($p->paymentMethod && in_array($code, config('payments.dunning.revoke_method_codes'), true)) {
            $p->paymentMethod->update(['status' => PaymentMethodStatus::Revoked, 'revoked_at' => now()]);
            $this->audit->log('payment_method.revoked_by_gateway', $p->paymentMethod, after: ['code' => $code]);
        }

        if ($recurring && $p->sequence_type === SequenceType::First) {
            // Le premier paiement (enrôlement) a échoué : le don régulier ne démarre pas.
            $recurring->update(['status' => RecurringStatus::Cancelled, 'status_reason' => 'first_payment_failed', 'cancelled_at' => now()]);
        } elseif ($recurring && $p->sequence_type === SequenceType::Recurring) {
            $this->scheduleDunning($p, $recurring, $code);
        }

        return new PaymentFailed($p, retryScheduled: $p->next_retry_at !== null);
    }

    /** Relances : nouvelle tentative à J+N si le code le permet, sinon « past_due ». */
    private function scheduleDunning(Payment $p, RecurringDonation $recurring, string $code): void
    {
        $delays = config('payments.dunning.retry_after_days');
        $retryable = ! in_array($code, config('payments.dunning.non_retryable_codes'), true)
            && $p->paymentMethod?->isActive()
            && isset($delays[$p->attempt_no - 1]);

        if ($retryable) {
            $p->next_retry_at = now()->addDays($delays[$p->attempt_no - 1]);

            return;
        }

        if (in_array($recurring->status, [RecurringStatus::Active, RecurringStatus::PastDue], true)) {
            $recurring->update(['status' => RecurringStatus::PastDue, 'status_reason' => $code]);
        }
    }

    private function onCancelled(Payment $p): null
    {
        if ($p->donation) {
            $p->donation->update(['status' => DonationStatus::Cancelled]);
        }

        $recurring = $p->recurringDonation;
        if ($recurring && $p->sequence_type === SequenceType::First && $recurring->status === RecurringStatus::Pending) {
            $recurring->update(['status' => RecurringStatus::Cancelled, 'status_reason' => 'checkout_cancelled', 'cancelled_at' => now()]);
        }

        return null;
    }

    private function onReversed(Payment $p, PaymentStatus $to, ?string $eventId): null
    {
        $p->reversed_at = now();
        $operation = $to === PaymentStatus::Refunded ? 'donation.refunded' : 'donation.charged_back';

        // Contre-passation : les fonds quittent la campagne et retournent au donateur via le PSP.
        $this->ledger->post(
            operation: $operation,
            debitAccount: $p->fundAccount(),
            creditAccount: $p->clearingAccount(),
            amountCents: $p->amount_cents,
            refType: 'payment',
            refId: $p->id,
            currency: $p->currency,
            campaignId: $p->campaign_id,
            userId: $p->user_id,
            gatewayEventId: $eventId,
            metadata: ['gateway' => $p->gateway, 'reference' => $p->gateway_reference],
        );

        if ($p->donation) {
            $p->donation->update(['status' => DonationStatus::Refunded]);
        }

        // Un rejet / une contestation du donateur : on suspend le don régulier sans insister.
        $recurring = $p->recurringDonation;
        if ($to === PaymentStatus::ChargedBack && $recurring && $recurring->status === RecurringStatus::Active) {
            $recurring->update(['status' => RecurringStatus::Paused, 'status_reason' => 'charged_back']);
        }

        return null;
    }

    private function enrollMethod(Payment $p, ChargeResult $result): PaymentMethod
    {
        $saved = $result->savedMethod;

        $existing = PaymentMethod::query()
            ->where('gateway', $p->gateway)
            ->where('user_id', $p->user_id)
            ->where(fn ($q) => $q->when($saved->mandateRef, fn ($q) => $q->orWhere('gateway_mandate_ref', $saved->mandateRef))
                ->when($saved->token, fn ($q) => $q->orWhere('gateway_token', $saved->token)))
            ->first();

        if ($existing) {
            return $existing;
        }

        $method = PaymentMethod::create([
            'user_id' => $p->user_id,
            'gateway' => $p->gateway,
            'type' => $saved->type,
            'status' => PaymentMethodStatus::Active,
            'gateway_customer_ref' => $saved->customerRef ?? $p->metadata['customer_ref'] ?? null,
            'gateway_mandate_ref' => $saved->mandateRef,
            'gateway_token' => $saved->token,
            'holder_name' => $saved->holderName,
            'iban_last4' => $saved->ibanLast4,
            'card_brand' => $saved->cardBrand,
            'card_last4' => $saved->cardLast4,
            'card_expiry' => $saved->cardExpiry,
            'consent' => $p->metadata['consent'] ?? null,
            'proof' => $saved->proof,
            'signed_at' => now(),
        ]);

        $this->audit->log('payment_method.enrolled', $method, after: ['type' => $method->type->value, 'gateway' => $method->gateway, 'payment' => $p->id]);

        return $method;
    }
}
