<?php

namespace App\Payments\Omnipay\Stripe\Message;

/** POST /v1/payment_methods type=sepa_debit */
class CreateSepaPaymentMethodRequest extends JsonRequest
{
    public function getData()
    {
        $p = $this->getParams();

        return $this->clean([
            'type' => 'sepa_debit',
            'sepa_debit' => ['iban' => $p['iban'] ?? null],
            'billing_details' => [
                'name' => $p['name'] ?? null,
                'email' => $p['email'] ?? null,
            ],
            'metadata' => $p['metadata'] ?? null,
        ]);
    }

    public function getEndpoint()
    {
        return $this->endpoint.'/payment_methods';
    }
}
