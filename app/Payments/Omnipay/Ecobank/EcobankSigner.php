<?php

namespace App\Payments\Omnipay\Ecobank;

/**
 * Sécurité des requêtes Ecobank (doc publique « Endpoint Flow ») :
 *
 *   requestToken = SHA512( clientId + affiliateCode + sourceCode + requestId + requestType + ipAddress + secretKey )
 *   secureHash   = SHA512( clientId + affiliateCode + sourceCode + requestId + requestType + ipAddress
 *                          + requestToken + <champs propres à l'endpoint, dans l'ordre> + secretKey )
 */
final class EcobankSigner
{
    public static function requestToken(array $h, string $secretKey): string
    {
        return hash('sha512', self::base($h).$secretKey);
    }

    /** @param list<string|int|float|null> $fields */
    public static function secureHash(array $h, string $requestToken, array $fields, string $secretKey): string
    {
        return hash('sha512', self::base($h).$requestToken.implode('', array_map(fn ($v) => (string) $v, $fields)).$secretKey);
    }

    private static function base(array $h): string
    {
        return $h['clientId'].$h['affiliateCode'].$h['sourceCode'].$h['requestId'].$h['requestType'].$h['ipAddress'];
    }
}
