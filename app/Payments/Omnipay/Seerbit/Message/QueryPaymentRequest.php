<?php

namespace App\Payments\Omnipay\Seerbit\Message;

/** GET /api/v3/payments/query/{paymentReference} */
class QueryPaymentRequest extends AbstractSeerbitRequest
{
    protected function method(): string
    {
        return 'GET';
    }

    protected function path(): string
    {
        return '/api/v3/payments/query/'.rawurlencode((string) $this->getTransactionReference());
    }

    public function getData()
    {
        $this->validate('bearerToken', 'transactionReference');

        return [];
    }
}
