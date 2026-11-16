<?php

namespace App\Payments\Omnipay\Stripe\Message;

/** GET /v1/checkout/sessions/{id} */
class FetchCheckoutSessionRequest extends JsonRequest
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
        return $this->endpoint.'/checkout/sessions/'.rawurlencode((string) ($this->getParams()['id'] ?? ''));
    }
}
