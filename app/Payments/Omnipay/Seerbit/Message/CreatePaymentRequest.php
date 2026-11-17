<?php

namespace App\Payments\Omnipay\Seerbit\Message;

/**
 * POST /api/v2/payments — Standard Checkout. Retourne data.payments.redirectLink.
 * `tokenize` n'est envoyé que si l'option est activée : il n'est pas documenté côté UBA
 * et doit être confirmé avec SeerBit (tokenisation de la carte sur la page hébergée).
 */
class CreatePaymentRequest extends AbstractSeerbitRequest
{
    protected function path(): string
    {
        return '/api/v2/payments';
    }

    public function getData()
    {
        $this->validate('publicKey', 'bearerToken', 'params');
        $p = $this->getParams();

        $data = [
            'publicKey' => $this->getPublicKey(),
            'amount' => (string) $p['amount'],
            'currency' => $p['currency'],
            'country' => $p['country'],
            'fullName' => $p['fullName'] ?? null,
            'paymentReference' => $p['paymentReference'],
            'email' => $p['email'],
            'productId' => $p['productId'] ?? null,
            'productDescription' => $p['productDescription'] ?? null,
            'callbackUrl' => $p['callbackUrl'],
        ];

        if (! empty($p['tokenize'])) {
            $data['tokenize'] = true;
        }

        return array_filter($data, fn ($v) => $v !== null);
    }
}
