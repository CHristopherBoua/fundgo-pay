<?php

namespace App\Payments\Omnipay\Stripe\Message;

/**
 * POST /v1/payment_intents — paiement initié par le marchand (MIT carte, ou SEPA sur mandat).
 * L'en-tête Idempotency-Key (= id du paiement local) empêche tout double débit en cas de retry.
 */
class OffSessionPaymentRequest extends JsonRequest
{
    public function getData()
    {
        $p = $this->getParams();

        return $this->clean([
            'amount' => $p['amount'] ?? null,
            'currency' => isset($p['currency']) ? strtolower($p['currency']) : null,
            'customer' => $p['customer'] ?? null,
            'payment_method' => $p['payment_method'] ?? null,
            'payment_method_types' => [$p['payment_method_type'] ?? 'card'],
            'mandate' => $p['mandate'] ?? null,
            'off_session' => true,
            'confirm' => true,
            'description' => $p['description'] ?? null,
            'statement_descriptor_suffix' => $p['statement_descriptor_suffix'] ?? null,
            'metadata' => $p['metadata'] ?? null,
        ]);
    }

    public function getEndpoint()
    {
        return $this->endpoint.'/payment_intents';
    }
}
