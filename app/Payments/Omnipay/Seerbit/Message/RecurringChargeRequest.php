<?php

namespace App\Payments\Omnipay\Seerbit\Message;

/** POST /api/v2/recurring/charge — débit sans le donateur d'une carte tokenisée. */
class RecurringChargeRequest extends AbstractSeerbitRequest
{
    protected function path(): string
    {
        return '/api/v2/recurring/charge';
    }

    public function getData()
    {
        $this->validate('publicKey', 'bearerToken', 'params');
        $p = $this->getParams();

        return [
            'amount' => (string) $p['amount'],
            'publicKey' => $this->getPublicKey(),
            'email' => $p['email'],
            'authorizationCode' => $p['authorizationCode'],
            'paymentReference' => $p['paymentReference'],
            'currency' => $p['currency'],
        ];
    }
}
