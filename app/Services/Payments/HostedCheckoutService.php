<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\GatewayManager;

/**
 * Démarre un paiement initié par le donateur sur la page du PSP (carte 3DS, UBA Checkout)
 * ou sur notre page OTP (Ecobank). Utilisé pour les dons ponctuels, le 1er paiement d'un
 * don régulier par carte, et chaque échéance « à régler par lien ».
 */
class HostedCheckoutService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly CustomerService $customers,
    ) {}

    /** @return string URL vers laquelle rediriger le donateur */
    public function start(Payment $payment): string
    {
        abort_unless($payment->status === PaymentStatus::Pending, 409, 'Ce paiement n\'est plus à régler.');

        $driver = $this->gateways->driver($payment->gateway);
        $customerRef = $this->customers->referenceFor($payment->user, $driver);

        $session = $driver->startHostedCheckout($payment, $customerRef, route('checkout.return', $payment));

        $payment->update([
            'gateway_reference' => $session->reference,
            'submitted_at' => now(),
            'metadata' => array_merge($payment->metadata ?? [], ['customer_ref' => $customerRef]),
        ]);

        return $session->redirectUrl;
    }
}
