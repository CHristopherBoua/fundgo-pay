<?php

namespace App\Payments\Omnipay\Stripe\Message;

/** GET /v1/payment_intents/{id}?expand[]=latest_charge&expand[]=payment_method */
class FetchPaymentIntentRequest extends JsonRequest
{
    public function getHttpMethod()
    {
        return 'GET';
    }

    public function getData()
    {
        $this->validate('params');

        return [];
    }

    public function getEndpoint()
    {
        $id = rawurlencode((string) ($this->getParams()['id'] ?? ''));

        return $this->endpoint.'/payment_intents/'.$id.'?expand%5B%5D=latest_charge&expand%5B%5D=payment_method';
    }
}
