<?php

namespace App\Payments\Omnipay\Seerbit;

use App\Payments\Omnipay\Seerbit\Message\CreatePaymentRequest;
use App\Payments\Omnipay\Seerbit\Message\EncryptKeysRequest;
use App\Payments\Omnipay\Seerbit\Message\QueryPaymentRequest;
use App\Payments\Omnipay\Seerbit\Message\RecurringChargeRequest;
use Omnipay\Common\AbstractGateway;

/**
 * Passerelle Omnipay pour l'API de paiement UBA, opérée par SeerBit
 * (https://docs-uba.seerbitapi.com). Aucun driver Omnipay officiel n'existant,
 * elle suit la structure Omnipay standard (gateway + requêtes + réponses).
 *
 * Utilisable par Omnipay::create('\App\Payments\Omnipay\Seerbit\SeerbitGateway').
 */
class SeerbitGateway extends AbstractGateway
{
    public function getName()
    {
        return 'UBA (SeerBit)';
    }

    public function getDefaultParameters()
    {
        return [
            'publicKey' => '',
            'secretKey' => '',
            'baseUrl' => 'https://seerbitapi.com',
            'bearerToken' => '',
        ];
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

    /** POST /api/v2/encrypt/keys — jeton Bearer à partir des clés privée et publique. */
    public function encryptKeys(array $parameters = []): EncryptKeysRequest
    {
        return $this->createRequest(EncryptKeysRequest::class, $parameters);
    }

    /** POST /api/v2/payments — Standard Checkout : lien de paiement hébergé par UBA. */
    public function purchase(array $parameters = []): CreatePaymentRequest
    {
        return $this->createRequest(CreatePaymentRequest::class, $parameters);
    }

    /** GET /api/v3/payments/query/{paymentReference} — statut de référence. */
    public function fetchTransaction(array $parameters = []): QueryPaymentRequest
    {
        return $this->createRequest(QueryPaymentRequest::class, $parameters);
    }

    /** POST /api/v2/recurring/charge — débit d'une carte tokenisée (authorizationCode). */
    public function recurringCharge(array $parameters = []): RecurringChargeRequest
    {
        return $this->createRequest(RecurringChargeRequest::class, $parameters);
    }
}
