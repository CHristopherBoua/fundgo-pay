<?php

namespace App\Payments\Omnipay\Ecobank\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RequestInterface;

/**
 * Réponse Ecobank, lecture tolérante : le code de retour et le contenu peuvent être
 * à la racine ou dans `response_content` / `data` selon le service (à confirmer en sandbox).
 */
class EcobankResponse extends AbstractResponse
{
    public function __construct(RequestInterface $request, $data, public readonly int $httpStatus = 200, private readonly array $successCodes = ['000', '00', '0', '200'])
    {
        parent::__construct($request, $data);
    }

    public function isSuccessful()
    {
        $code = $this->getCode();

        return $this->httpStatus < 300 && ($code === null || in_array($code, $this->successCodes, true));
    }

    public function getCode()
    {
        foreach (['response_code', 'responseCode', 'statusCode', 'code'] as $key) {
            if (isset($this->data[$key]) && is_scalar($this->data[$key])) {
                return (string) $this->data[$key];
            }
        }

        return null;
    }

    public function getMessage()
    {
        foreach (['response_message', 'responseMessage', 'message', 'statusMessage'] as $key) {
            if (! empty($this->data[$key]) && is_scalar($this->data[$key])) {
                return (string) $this->data[$key];
            }
        }

        return null;
    }

    /** Cherche une valeur dans le contenu puis à la racine (ex. trackingId, transactionReference, token). */
    public function get(string ...$keys): mixed
    {
        foreach (['response_content', 'responseContent', 'data', 'content'] as $container) {
            $content = $this->data[$container] ?? null;
            if (is_string($content)) {
                $content = json_decode($content, true);
            }
            foreach ($keys as $key) {
                if (is_array($content) && isset($content[$key])) {
                    return $content[$key];
                }
            }
        }
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                return $this->data[$key];
            }
        }

        return null;
    }

    public function getTransactionReference()
    {
        return $this->get('transactionReference', 'transactionRef', 'reference');
    }
}
