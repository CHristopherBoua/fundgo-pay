<?php

namespace App\Payments\Omnipay\Stripe\Message;

/**
 * POST /v1/setup_intents — confirme immédiatement un mandat SEPA signé en ligne.
 * La preuve d'acceptation (IP, user-agent) est transmise à Stripe et conservée chez nous.
 */
class CreateSepaSetupIntentRequest extends JsonRequest
{
    public function getData()
    {
        $p = $this->getParams();

        return $this->clean([
            'customer' => $p['customer'] ?? null,
            'payment_method' => $p['payment_method'] ?? null,
            'payment_method_types' => ['sepa_debit'],
            'usage' => 'off_session',
            'confirm' => true,
            'mandate_data' => [
                'customer_acceptance' => [
                    'type' => 'online',
                    'online' => [
                        'ip_address' => $p['ip_address'] ?? null,
                        'user_agent' => $p['user_agent'] ?? null,
                    ],
                ],
            ],
            'metadata' => $p['metadata'] ?? null,
        ]);
    }

    public function getEndpoint()
    {
        return $this->endpoint.'/setup_intents';
    }
}
