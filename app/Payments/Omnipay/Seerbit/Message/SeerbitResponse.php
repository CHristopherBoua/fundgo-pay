<?php

namespace App\Payments\Omnipay\Seerbit\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RequestInterface;

/**
 * Enveloppe SeerBit : { "status": "SUCCESS"|"FAILED", "data": { "code": "00", "message": "...", "payments": {...} } }.
 */
class SeerbitResponse extends AbstractResponse
{
    public function __construct(RequestInterface $request, $data, public readonly int $httpStatus = 200)
    {
        parent::__construct($request, $data);
    }

    public function isSuccessful()
    {
        return $this->httpStatus < 400 && strtoupper((string) ($this->data['status'] ?? '')) === 'SUCCESS';
    }

    public function getCode()
    {
        return $this->data['data']['code'] ?? $this->data['code'] ?? null;
    }

    public function getMessage()
    {
        return $this->data['data']['message'] ?? $this->data['message'] ?? $this->data['error'] ?? null;
    }

    public function getPayments(): array
    {
        return (array) ($this->data['data']['payments'] ?? []);
    }

    public function isRedirect()
    {
        return ! empty($this->getPayments()['redirectLink']);
    }

    public function getRedirectUrl()
    {
        return $this->getPayments()['redirectLink'] ?? null;
    }

    public function getTransactionReference()
    {
        return $this->getPayments()['paymentReference'] ?? null;
    }
}
