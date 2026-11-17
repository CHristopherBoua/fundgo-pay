<?php

namespace App\Payments\Omnipay\Ecobank\Message;

use App\Payments\Omnipay\Ecobank\EcobankGateway;
use App\Payments\Omnipay\Ecobank\EcobankSigner;
use Illuminate\Support\Str;

/**
 * Requête Direct Debit générique : en-têtes métier + requestToken + champs + secureHash.
 * Le payload est plat (à confirmer en sandbox : le format exact n'est pas public).
 */
class DirectDebitRequest extends AbstractEcobankRequest
{
    public function getData()
    {
        $this->validate('baseUrl', 'bearerToken', 'clientId', 'secretKey', 'affiliateCode', 'sourceCode', 'operation');

        $operation = (string) $this->getParameter('operation');
        $endpoint = EcobankGateway::ENDPOINTS[$operation];
        $fields = (array) $this->getParameter('params');
        if (in_array('userType', $endpoint['hash'], true)) {
            $fields['userType'] ??= $this->getParameter('userType');
        }

        $header = [
            'clientId' => (string) $this->getParameter('clientId'),
            'affiliateCode' => (string) $this->getParameter('affiliateCode'),
            'sourceCode' => (string) $this->getParameter('sourceCode'),
            'requestId' => (string) ($this->getParameter('requestId') ?: Str::upper(Str::ulid())),
            'requestType' => (string) ($this->getParameter('requestTypes')[$operation] ?? Str::upper(Str::snake($operation))),
            'ipAddress' => (string) $this->getParameter('ipAddress'),
        ];

        $secret = (string) $this->getParameter('secretKey');
        $requestToken = EcobankSigner::requestToken($header, $secret);
        $hashed = array_map(fn (string $k) => $fields[$k] ?? '', $endpoint['hash']);

        return array_merge($header, ['requestToken' => $requestToken], $fields, [
            'secureHash' => EcobankSigner::secureHash($header, $requestToken, $hashed, $secret),
        ]);
    }

    public function sendData($data)
    {
        $path = EcobankGateway::ENDPOINTS[(string) $this->getParameter('operation')]['path'];

        return $this->post($path, $data, ['Authorization' => 'Bearer '.$this->getParameter('bearerToken')]);
    }
}
