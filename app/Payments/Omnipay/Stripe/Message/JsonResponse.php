<?php

namespace App\Payments\Omnipay\Stripe\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RequestInterface;

class JsonResponse extends AbstractResponse
{
    public function __construct(RequestInterface $request, $data, protected array $headers = [])
    {
        parent::__construct($request, is_string($data) ? (json_decode($data, true) ?: []) : (array) $data);
    }

    public function isSuccessful()
    {
        return ! isset($this->data['error']);
    }

    public function getMessage()
    {
        return $this->data['error']['message'] ?? null;
    }

    public function getCode()
    {
        return $this->data['error']['decline_code'] ?? $this->data['error']['code'] ?? null;
    }

    public function getTransactionReference()
    {
        return $this->data['id'] ?? null;
    }
}
