<?php

namespace App\Payments\Omnipay\Stripe\Message;

/** POST /v1/refunds payment_intent=pi_… */
class RefundPaymentIntentRequest extends JsonRequest
{
    public function getData()
    {
        $p = $this->getParams();

        return $this->clean([
            'payment_intent' => $p['payment_intent'] ?? null,
            'amount' => $p['amount'] ?? null,
            'reason' => $p['reason'] ?? 'requested_by_customer',
            'metadata' => $p['metadata'] ?? null,
        ]);
    }

    public function getEndpoint()
    {
        return $this->endpoint.'/refunds';
    }
}
