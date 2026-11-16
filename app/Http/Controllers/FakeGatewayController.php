<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Payments\Drivers\FakeDriver;
use App\Payments\GatewayManager;
use App\Services\Payments\GatewayEventIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Page de paiement hébergée simulée : reproduit le parcours PSP (redirection, choix,
 * webhook signé, retour) sans aucun appel réseau. Désactivée en production.
 */
class FakeGatewayController extends Controller
{
    public function show(Request $request, string $payment): View
    {
        $payment = $request->user()->payments()->where('gateway', 'fake')->findOrFail($payment);

        return view('fake.checkout', ['payment' => $payment, 'return' => $this->safeReturn($request, $payment->id)]);
    }

    public function complete(Request $request, string $payment, GatewayManager $gateways, GatewayEventIngestor $ingestor): RedirectResponse
    {
        $payment = $request->user()->payments()->where('gateway', 'fake')->findOrFail($payment);
        $decision = $request->validate(['decision' => 'required|in:pay,decline,cancel'])['decision'];

        if ($payment->status === PaymentStatus::Pending) {
            $body = json_encode([
                'event_id' => 'fevt_'.Str::lower(Str::random(16)),
                'payment_id' => $payment->id,
                'reference' => $payment->gateway_reference,
                'status' => match ($decision) {
                    'pay' => PaymentStatus::Paid->value,
                    'decline' => PaymentStatus::Failed->value,
                    'cancel' => PaymentStatus::Cancelled->value,
                },
                'sequence_type' => $payment->sequence_type->value,
                'customer' => $payment->metadata['customer_ref'] ?? null,
                'holder' => $request->user()->name,
            ]);

            /** @var FakeDriver $driver */
            $driver = $gateways->driver('fake');
            $webhook = Request::create('/webhooks/fake', 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_FAKE_SIGNATURE' => $driver->sign($body),
            ], content: $body);

            $ingestor->ingest('fake', $driver->parseWebhook($webhook));
        }

        return redirect()->to($this->safeReturn($request, $payment->id));
    }

    /** N'accepte qu'un retour vers notre propre domaine (pas d'open redirect). */
    private function safeReturn(Request $request, string $paymentId): string
    {
        $return = (string) $request->query('return', $request->input('return', ''));
        $fallback = route('checkout.return', $paymentId);

        return str_starts_with($return, url('/').'/') ? $return : $fallback;
    }
}
