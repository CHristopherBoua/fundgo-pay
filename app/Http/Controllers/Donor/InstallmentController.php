<?php

namespace App\Http\Controllers\Donor;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\Data\ChargeResult;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use App\Services\Payments\HostedCheckoutService;
use App\Services\Payments\PaymentProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lien « Régler mon don » d'une échéance (dons réguliers par lien de paiement).
 * Chaque ouverture de session PSP utilise une nouvelle tentative : certains PSP
 * (UBA) refusent de réutiliser une référence de paiement.
 */
class InstallmentController extends Controller
{
    public function pay(Request $request, string $payment, GatewayManager $gateways, PaymentProcessor $processor, HostedCheckoutService $checkout): RedirectResponse
    {
        $installment = $request->user()->payments()->whereNotNull('recurring_donation_id')->findOrFail($payment);
        abort_unless(! empty($installment->metadata['pay_by_link']), 404);

        $latest = Payment::query()
            ->where('recurring_donation_id', $installment->recurring_donation_id)
            ->where('period', $installment->period?->toDateString())
            ->orderByDesc('attempt_no')
            ->first();

        // Une session déjà ouverte : on relit son statut (le donateur a pu payer dans un autre onglet).
        if ($latest->status === PaymentStatus::Pending && $latest->gateway_reference) {
            try {
                $latest = $processor->apply($latest, $gateways->driver($latest->gateway)->fetchPayment($latest), actor: 'installment_link');
            } catch (PaymentGatewayException) {
                // on continue : une nouvelle tentative sera ouverte
            }
        }

        if ($latest->status === PaymentStatus::Paid) {
            return redirect()->route('dashboard')->with('status', 'Cette échéance est déjà réglée. Merci !');
        }

        $expiresOn = $latest->metadata['link_expires_on'] ?? null;
        if (($latest->status === PaymentStatus::Failed && $latest->failure_code === 'not_paid')
            || ($expiresOn && CarbonImmutable::today()->gte(CarbonImmutable::parse($expiresOn)))) {
            return redirect()->route('dashboard')->withErrors(['installment' => 'Ce lien a expiré. La prochaine échéance vous sera envoyée.']);
        }

        if ($latest->status === PaymentStatus::Processing) {
            return redirect()->route('checkout.return', $latest->id);
        }

        $attempt = $latest->status === PaymentStatus::Pending && ! $latest->gateway_reference
            ? $latest
            : $this->newAttempt($latest, $processor);

        return redirect()->away($checkout->start($attempt));
    }

    private function newAttempt(Payment $previous, PaymentProcessor $processor): Payment
    {
        if ($previous->status === PaymentStatus::Pending) {
            $processor->apply($previous, new ChargeResult(PaymentStatus::Cancelled, failureCode: 'superseded'), actor: 'installment_link');
        }

        return DB::transaction(fn () => Payment::create([
            'user_id' => $previous->user_id,
            'recurring_donation_id' => $previous->recurring_donation_id,
            'campaign_id' => $previous->campaign_id,
            'gateway' => $previous->gateway,
            'method_type' => $previous->method_type,
            'sequence_type' => $previous->sequence_type,
            'amount_cents' => $previous->amount_cents,
            'currency' => $previous->currency,
            'status' => PaymentStatus::Pending,
            'period' => $previous->period?->toDateString(),
            'attempt_no' => $previous->attempt_no + 1,
            'metadata' => array_merge($previous->metadata ?? [], ['retry_of' => $previous->id]),
        ]));
    }
}
