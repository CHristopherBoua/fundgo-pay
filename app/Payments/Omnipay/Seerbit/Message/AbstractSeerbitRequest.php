<?php

namespace App\Payments\Omnipay\Seerbit\Message;

use Omnipay\Common\Message\AbstractRequest;

abstract class AbstractSeerbitRequest extends AbstractRequest
{
    abstract protected function path(): string;

    protected function method(): string
    {
        return 'POST';
    }

    protected function authenticated(): bool
    {
        return true;
    }

    public function getPublicKey()
    {
        return $this->getParameter('publicKey');
    }

    public function setPublicKey($value)
    {
        return $this->setParameter('publicKey', $value);
    }

    public function getSecretKey()
    {
        return $this->getParameter('secretKey');
    }

    public function setSecretKey($value)
    {
        return $this->setParameter('secretKey', $value);
    }

    public function getBaseUrl()
    {
        return $this->getParameter('baseUrl');
    }

    public function setBaseUrl($value)
    {
        return $this->setParameter('baseUrl', $value);
    }

    public function getBearerToken()
    {
        return $this->getParameter('bearerToken');
    }

    public function setBearerToken($value)
    {
        return $this->setParameter('bearerToken', $value);
    }

    public function getParams(): array
    {
        return (array) $this->getParameter('params');
    }

    public function setParams(array $value)
    {
        return $this->setParameter('params', $value);
    }

    public function sendData($data)
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->authenticated()) {
            $headers['Authorization'] = 'Bearer '.$this->getBearerToken();
        }

        $response = $this->httpClient->request(
            $this->method(),
            rtrim((string) $this->getBaseUrl(), '/').$this->path(),
            $headers,
            $this->method() === 'GET' ? null : json_encode($data, JSON_UNESCAPED_SLASHES),
        );

        $decoded = json_decode((string) $response->getBody(), true);

        return $this->response = new SeerbitResponse($this, is_array($decoded) ? $decoded : [], $response->getStatusCode());
    }
}
