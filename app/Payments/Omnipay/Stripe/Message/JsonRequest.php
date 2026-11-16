<?php

namespace App\Payments\Omnipay\Stripe\Message;

use Omnipay\Stripe\Message\AbstractRequest;

/**
 * Base des requêtes Stripe ajoutées : paramètres libres (`params`) + réponse JSON simple.
 * Les tableaux imbriqués sont encodés par omnipay/stripe via http_build_query
 * (ex. mandate_data[customer_acceptance][type]=online), format attendu par Stripe.
 */
abstract class JsonRequest extends AbstractRequest
{
    public function getParams(): array
    {
        return (array) $this->getParameter('params');
    }

    public function setParams(array $value): static
    {
        return $this->setParameter('params', $value);
    }

    protected function createResponse($data, $headers = [])
    {
        return $this->response = new JsonResponse($this, $data, $headers);
    }

    /** Supprime récursivement les null (Stripe rejette les clés vides). */
    protected function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->clean($value);
            } elseif ($value === null) {
                unset($data[$key]);
            } elseif (is_bool($value)) {
                $data[$key] = $value ? 'true' : 'false';
            }
        }

        return $data;
    }
}
