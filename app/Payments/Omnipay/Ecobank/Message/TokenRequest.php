<?php

namespace App\Payments\Omnipay\Ecobank\Message;

/**
 * Jeton Bearer d'un service (validité ~5 minutes). Le payload exact est visible dans la
 * sandbox ; `serviceCode` est documenté, les autres identifiants viennent de `tokenCredentials`.
 */
class TokenRequest extends AbstractEcobankRequest
{
    public function getData()
    {
        $this->validate('baseUrl', 'subscriptionKey', 'serviceCode');

        return array_merge((array) $this->getParameter('tokenCredentials'), ['serviceCode' => $this->getParameter('serviceCode')]);
    }

    public function sendData($data)
    {
        return $this->post((string) $this->getParameter('tokenPath'), $data);
    }
}
