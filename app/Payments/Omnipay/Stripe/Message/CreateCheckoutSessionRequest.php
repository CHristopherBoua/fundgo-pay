<?php

namespace App\Payments\Omnipay\Stripe\Message;

/**
 * POST /v1/checkout/sessions — page de paiement hébergée par Stripe (3DS / SCA géré par Stripe).
 * `save_for_future` : enrôle la carte (setup_future_usage=off_session) pour les dons récurrents.
 */
class CreateCheckoutSessionRequest extends JsonRequest
{
    public function getData()
    {
        $p = $this->getParams();
        $metadata = $p['metadata'] ?? [];

        return $this->clean([
            'mode' => 'payment',
            'customer' => $p['customer'] ?? null,
            'client_reference_id' => $metadata['payment_id'] ?? null,
            'payment_method_types' => ['card'],
            'locale' => 'fr',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($p['currency'] ?? 'eur'),
                    'unit_amount' => $p['amount'] ?? null,
                    'product_data' => ['name' => $p['description'] ?? 'Don'],
                ],
            ]],
            'payment_intent_data' => [
                'description' => $p['description'] ?? null,
                'metadata' => $metadata,
                'setup_future_usage' => ! empty($p['save_for_future']) ? 'off_session' : null,
            ],
            'metadata' => $metadata,
            'success_url' => $p['return_url'] ?? null,
            'cancel_url' => $p['cancel_url'] ?? ($p['return_url'] ?? null),
        ]);
    }

    public function getEndpoint()
    {
        return $this->endpoint.'/checkout/sessions';
    }
}
