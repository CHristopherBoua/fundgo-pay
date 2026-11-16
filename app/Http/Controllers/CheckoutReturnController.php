<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Retour du donateur après la page de paiement hébergée.
 * Le webhook reste la source de vérité ; on relit néanmoins le statut chez le PSP
 * pour afficher immédiatement le bon résultat (et pallier un webhook injoignable en local).
 */
class CheckoutReturnController extends Controller
{
    public function __invoke(Request $request, string $payment, GatewayManager $gateways, PaymentProcessor $processor): View
    {
        $payment = $request->user()->payments()->findOrFail($payment);

        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true) && $payment->gateway_reference) {
            try {
                $result = $gateways->driver($payment->gateway)->fetchPayment($payment);
                $payment = $processor->apply($payment, $result, actor: 'checkout_return');
            } catch (PaymentGatewayException) {
                // le webhook finira par trancher
            }
        }

        return view('checkout.return', ['payment' => $payment->load(['campaign', 'recurringDonation'])]);
    }
}
