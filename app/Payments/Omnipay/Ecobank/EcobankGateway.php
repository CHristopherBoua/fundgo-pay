<?php

namespace App\Payments\Omnipay\Ecobank;

use App\Payments\Omnipay\Ecobank\Message\DirectDebitRequest;
use App\Payments\Omnipay\Ecobank\Message\TokenRequest;
use Omnipay\Common\AbstractGateway;

/**
 * Passerelle Omnipay « Direct Payment From Ecobank Account » (Direct Debit Ecobank).
 * Chaque débit est validé par le client avec un OTP de l'appli Ecobank Authenticator.
 *
 * Endpoints (doc publique, {base_url}/api/v2/integration/directdebit/...) :
 *   otp/send, otp/validation, otp/resend, createprofile, completeprofile,
 *   getprofilebyuserid, active, deactive, payment, payment/validation
 *
 * Les champs exacts des payloads, les valeurs de requestType et le format de réponse
 * ne sont visibles que dans la sandbox Ecobank : ils sont configurables
 * (config/payments.php > gateways.ecobank) et À CONFIRMER avant mise en production.
 */
class EcobankGateway extends AbstractGateway
{
    public const ENDPOINTS = [
        'sendOtp' => ['path' => '/api/v2/integration/directdebit/otp/send', 'hash' => ['userId']],
        'validateOtp' => ['path' => '/api/v2/integration/directdebit/otp/validation', 'hash' => ['code', 'trackingId', 'userType']],
        'resendOtp' => ['path' => '/api/v2/integration/directdebit/otp/resend', 'hash' => ['trackingId']],
        'createProfile' => ['path' => '/api/v2/integration/directdebit/createprofile', 'hash' => ['userId', 'userType']],
        'completeProfile' => ['path' => '/api/v2/integration/directdebit/completeprofile', 'hash' => ['token', 'userId']],
        'getProfile' => ['path' => '/api/v2/integration/directdebit/getprofilebyuserid', 'hash' => ['userId']],
        'activateProfile' => ['path' => '/api/v2/integration/directdebit/active', 'hash' => ['profileId']],
        'deactivateProfile' => ['path' => '/api/v2/integration/directdebit/deactive', 'hash' => ['profileId']],
        'initiatePayment' => ['path' => '/api/v2/integration/directdebit/payment', 'hash' => ['amount', 'currency', 'description']],
        'validatePayment' => ['path' => '/api/v2/integration/directdebit/payment/validation', 'hash' => ['token', 'transactionReference']],
    ];

    public function getName()
    {
        return 'Ecobank Direct Debit';
    }

    public function getDefaultParameters()
    {
        return [
            'baseUrl' => '',
            'tokenPath' => '/api/v2/token',
            'subscriptionKey' => '',
            'serviceCode' => '',
            'clientId' => '',
            'secretKey' => '',
            'affiliateCode' => '',
            'sourceCode' => '',
            'ipAddress' => '',
            'userType' => '',
            'tokenCredentials' => [],
            'requestTypes' => [],
            'successCodes' => ['000', '00', '0', '200'],
            'bearerToken' => '',
        ];
    }

    public function __call($name, $arguments)
    {
        if (isset(self::ENDPOINTS[$name])) {
            return $this->createRequest(DirectDebitRequest::class, array_merge($arguments[0] ?? [], ['operation' => $name]));
        }

        throw new \BadMethodCallException("Unknown Ecobank operation [$name]");
    }

    public function token(array $parameters = []): TokenRequest
    {
        return $this->createRequest(TokenRequest::class, $parameters);
    }

    // Accesseurs Omnipay (initialize() appelle les setters)
    public function setBaseUrl($v) { return $this->setParameter('baseUrl', $v); }
    public function setTokenPath($v) { return $this->setParameter('tokenPath', $v); }
    public function setSubscriptionKey($v) { return $this->setParameter('subscriptionKey', $v); }
    public function setServiceCode($v) { return $this->setParameter('serviceCode', $v); }
    public function setClientId($v) { return $this->setParameter('clientId', $v); }
    public function setSecretKey($v) { return $this->setParameter('secretKey', $v); }
    public function setAffiliateCode($v) { return $this->setParameter('affiliateCode', $v); }
    public function setSourceCode($v) { return $this->setParameter('sourceCode', $v); }
    public function setIpAddress($v) { return $this->setParameter('ipAddress', $v); }
    public function setUserType($v) { return $this->setParameter('userType', $v); }
    public function setTokenCredentials($v) { return $this->setParameter('tokenCredentials', (array) $v); }
    public function setRequestTypes($v) { return $this->setParameter('requestTypes', (array) $v); }
    public function setSuccessCodes($v) { return $this->setParameter('successCodes', (array) $v); }
    public function setBearerToken($v) { return $this->setParameter('bearerToken', $v); }
    public function getBearerToken() { return $this->getParameter('bearerToken'); }
}
