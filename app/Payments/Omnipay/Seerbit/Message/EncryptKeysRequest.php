<?php

namespace App\Payments\Omnipay\Seerbit\Message;

/** POST /api/v2/encrypt/keys  { "key": "privateKey.publicKey" } -> data.EncryptedSecKey.encryptedKey */
class EncryptKeysRequest extends AbstractSeerbitRequest
{
    protected function path(): string
    {
        return '/api/v2/encrypt/keys';
    }

    protected function authenticated(): bool
    {
        return false;
    }

    public function getData()
    {
        $this->validate('publicKey', 'secretKey');

        return ['key' => $this->getSecretKey().'.'.$this->getPublicKey()];
    }
}
