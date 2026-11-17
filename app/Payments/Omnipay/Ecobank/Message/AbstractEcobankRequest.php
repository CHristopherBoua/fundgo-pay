<?php

namespace App\Payments\Omnipay\Ecobank\Message;

use Omnipay\Common\Message\AbstractRequest;

abstract class AbstractEcobankRequest extends AbstractRequest
{
    private const PARAMS = [
        'baseUrl', 'tokenPath', 'subscriptionKey', 'serviceCode', 'clientId', 'secretKey', 'affiliateCode',
        'sourceCode', 'ipAddress', 'userType', 'tokenCredentials', 'requestTypes', 'successCodes',
        'bearerToken', 'operation', 'params', 'requestId',
    ];

    public function __call($name, $arguments)
    {
        $prefix = substr($name, 0, 3);
        $key = lcfirst(substr($name, 3));
        if (in_array($key, self::PARAMS, true)) {
            return $prefix === 'set' ? $this->setParameter($key, $arguments[0] ?? null) : $this->getParameter($key);
        }

        throw new \BadMethodCallException("Unknown method [$name]");
    }

    /** initialize() n'utilise que des setters existants : on les déclare via __call + method_exists. */
    public function initialize(array $parameters = [])
    {
        parent::initialize([]);
        foreach ($parameters as $key => $value) {
            if (in_array($key, self::PARAMS, true) || method_exists($this, 'set'.ucfirst($key))) {
                $this->setParameter($key, $value);
            }
        }

        return $this;
    }

    protected function post(string $path, array $body, array $extraHeaders = []): EcobankResponse
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'Ocp-Apim-Subscription-Key' => (string) $this->getParameter('subscriptionKey'),
        ], $extraHeaders);

        $response = $this->httpClient->request(
            'POST',
            rtrim((string) $this->getParameter('baseUrl'), '/').$path,
            $headers,
            json_encode($body, JSON_UNESCAPED_SLASHES),
        );

        $decoded = json_decode((string) $response->getBody(), true);

        return $this->response = new EcobankResponse(
            $this,
            is_array($decoded) ? $decoded : [],
            $response->getStatusCode(),
            (array) $this->getParameter('successCodes'),
        );
    }
}
