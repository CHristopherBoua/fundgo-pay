<?php

namespace App\Payments\Omnipay\Stripe;

use App\Payments\Omnipay\Stripe\Message\CreateCheckoutSessionRequest;
use App\Payments\Omnipay\Stripe\Message\CreateSepaPaymentMethodRequest;
use App\Payments\Omnipay\Stripe\Message\FetchCheckoutSessionRequest;
use App\Payments\Omnipay\Stripe\Message\CreateSepaSetupIntentRequest;
use App\Payments\Omnipay\Stripe\Message\FetchPaymentIntentRequest;
use App\Payments\Omnipay\Stripe\Message\OffSessionPaymentRequest;
use App\Payments\Omnipay\Stripe\Message\RefundPaymentIntentRequest;
use Omnipay\Stripe\PaymentIntentsGateway;

/**
 * Passerelle Omnipay Stripe étendue (pattern officiel Omnipay : sous-classer une
 * passerelle et ajouter des requêtes). Ajoute ce que omnipay/stripe ne couvre pas :
 * mandat SEPA par SetupIntent, Checkout hébergé, PaymentIntent hors session SEPA/carte,
 * remboursement par PaymentIntent.
 *
 * Utilisable par Omnipay::create('\App\Payments\Omnipay\Stripe\FundgoStripeGateway').
 */
class FundgoStripeGateway extends PaymentIntentsGateway
{
    public function getName()
    {
        return 'Stripe (FundGo)';
    }

    public function createSepaPaymentMethod(array $parameters = []): CreateSepaPaymentMethodRequest
    {
        return $this->createRequest(CreateSepaPaymentMethodRequest::class, $parameters);
    }

    public function createSepaSetupIntent(array $parameters = []): CreateSepaSetupIntentRequest
    {
        return $this->createRequest(CreateSepaSetupIntentRequest::class, $parameters);
    }

    public function createCheckoutSession(array $parameters = []): CreateCheckoutSessionRequest
    {
        return $this->createRequest(CreateCheckoutSessionRequest::class, $parameters);
    }

    public function offSessionPayment(array $parameters = []): OffSessionPaymentRequest
    {
        return $this->createRequest(OffSessionPaymentRequest::class, $parameters);
    }

    public function fetchPaymentIntentExpanded(array $parameters = []): FetchPaymentIntentRequest
    {
        return $this->createRequest(FetchPaymentIntentRequest::class, $parameters);
    }

    public function fetchCheckoutSession(array $parameters = []): FetchCheckoutSessionRequest
    {
        return $this->createRequest(FetchCheckoutSessionRequest::class, $parameters);
    }

    public function refundPaymentIntent(array $parameters = []): RefundPaymentIntentRequest
    {
        return $this->createRequest(RefundPaymentIntentRequest::class, $parameters);
    }
}
