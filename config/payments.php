<?php

use App\Payments\Drivers\FakeDriver;
use App\Payments\Drivers\MollieDriver;
use App\Payments\Drivers\EcobankDriver;
use App\Payments\Drivers\StripeDriver;
use App\Payments\Drivers\UbaDriver;
use App\Payments\Omnipay\Ecobank\EcobankGateway;
use App\Payments\Omnipay\Seerbit\SeerbitGateway;
use App\Payments\Omnipay\Stripe\FundgoStripeGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Routage des moyens de paiement vers les passerelles
    |--------------------------------------------------------------------------
    | Chaque type de moyen de paiement peut passer par un PSP différent :
    | ex. SEPA via Mollie (mandats B2C/Core, coût fixe) et carte via Stripe.
    | Changer de PSP = changer une variable d'environnement.
    */
    'default' => env('PAYMENTS_GATEWAY', 'fake'),

    // Routage par défaut (devises sans règle spécifique ci-dessous).
    'routing' => [
        'sepa_debit' => env('PAYMENTS_SEPA_GATEWAY'),
        'card' => env('PAYMENTS_CARD_GATEWAY'),
        'ecobank_account' => env('PAYMENTS_ECOBANK_GATEWAY'),
    ],

    // Routage par devise : prioritaire sur `routing`. Ex. XOF par carte via UBA.
    'routing_by_currency' => [
        'XOF' => ['card' => env('PAYMENTS_XOF_CARD_GATEWAY'), 'ecobank_account' => env('PAYMENTS_XOF_ECOBANK_GATEWAY')],
        'XAF' => ['card' => env('PAYMENTS_XAF_CARD_GATEWAY'), 'ecobank_account' => env('PAYMENTS_XAF_ECOBANK_GATEWAY')],
        'NGN' => ['card' => env('PAYMENTS_NGN_CARD_GATEWAY'), 'ecobank_account' => env('PAYMENTS_NGN_ECOBANK_GATEWAY')],
        'USD' => ['card' => env('PAYMENTS_USD_CARD_GATEWAY'), 'ecobank_account' => env('PAYMENTS_USD_ECOBANK_GATEWAY')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Devises
    |--------------------------------------------------------------------------
    | Tous les montants sont stockés en unités mineures entières (colonnes *_cents) :
    | centimes pour EUR/USD, kobo pour NGN, francs pour XOF/XAF (exposant ISO 4217 = 0).
    | min / max sont en unités mineures ; `suggested` en unités majeures.
    | `methods` : moyens proposés aux donateurs pour cette devise.
    */
    'currency' => 'EUR', // devise par défaut (dons hors campagne)
    'currencies' => [
        'EUR' => ['decimals' => 2, 'min' => (int) env('DONATION_MIN_CENTS', 100), 'max' => (int) env('DONATION_MAX_CENTS', 1_000_000),
            'suggested' => [10, 20, 50, 100], 'methods' => ['sepa_debit', 'card']],
        'USD' => ['decimals' => 2, 'min' => 100, 'max' => 1_000_000, 'suggested' => [10, 25, 50, 100], 'methods' => ['card', 'ecobank_account']],
        'XOF' => ['decimals' => 0, 'min' => 500, 'max' => 10_000_000, 'suggested' => [2_000, 5_000, 10_000, 25_000], 'methods' => ['card', 'ecobank_account']],
        'XAF' => ['decimals' => 0, 'min' => 500, 'max' => 10_000_000, 'suggested' => [2_000, 5_000, 10_000, 25_000], 'methods' => ['card', 'ecobank_account']],
        'NGN' => ['decimals' => 2, 'min' => 50_000, 'max' => 1_000_000_000, 'suggested' => [2_000, 5_000, 10_000, 50_000], 'methods' => ['card', 'ecobank_account']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Échéances « à régler par lien »
    |--------------------------------------------------------------------------
    | Pour les passerelles sans prélèvement automatique (Ecobank : OTP à chaque
    | débit ; UBA sans tokenisation hébergée), chaque échéance d'un don régulier
    | est envoyée au donateur sous forme de lien de paiement.
    */
    'pay_by_link' => [
        'reminder_after_days' => [3, 7],
        'expires_after_days' => 14,
        'past_due_after_missed' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Passerelles (adaptateurs Omnipay)
    |--------------------------------------------------------------------------
    | `omnipay` : nom court Omnipay ("Mollie") ou classe complète d'une passerelle
    | Omnipay étendue (ex. FundgoStripeGateway qui ajoute SEPA / Checkout / SetupIntent).
    */
    'gateways' => [
        'mollie' => [
            'driver' => MollieDriver::class,
            'omnipay' => 'Mollie',
            'options' => [
                'apiKey' => env('MOLLIE_API_KEY'),
            ],
            'card_method' => 'creditcard',
        ],

        'stripe' => [
            'driver' => StripeDriver::class,
            'omnipay' => '\\'.FundgoStripeGateway::class,
            'options' => [
                'apiKey' => env('STRIPE_SECRET_KEY'),
            ],
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'webhook_tolerance' => 300,
        ],

        // UBA (API SeerBit) — https://docs-uba.seerbitapi.com
        'uba' => [
            'driver' => UbaDriver::class,
            'omnipay' => '\\'.SeerbitGateway::class,
            'options' => [
                'publicKey' => env('UBA_PUBLIC_KEY'),
                'secretKey' => env('UBA_SECRET_KEY'),
                'baseUrl' => env('UBA_BASE_URL', 'https://seerbitapi.com'),
            ],
            'currencies' => array_filter(explode(',', (string) env('UBA_CURRENCIES', 'NGN,XOF,XAF,USD'))),
            'country_by_currency' => ['NGN' => 'NG', 'XOF' => env('UBA_XOF_COUNTRY', 'SN'), 'XAF' => env('UBA_XAF_COUNTRY', 'CM'), 'USD' => env('UBA_USD_COUNTRY', 'NG')],
            // Débits automatiques par carte : n'activer qu'après confirmation par SeerBit que la page
            // hébergée renvoie un authorizationCode (sinon échéances « à régler par lien »).
            'hosted_tokenization' => (bool) env('UBA_HOSTED_TOKENIZATION', false),
            'token_ttl' => (int) env('UBA_TOKEN_TTL', 1800),
        ],

        // Ecobank — Direct Payment From Ecobank Account (https://apimuat-developer.ecobank.com/documentation)
        // Champs marqués « sandbox » : à relever dans la sandbox Ecobank avant mise en production.
        'ecobank' => [
            'driver' => EcobankDriver::class,
            'omnipay' => '\\'.EcobankGateway::class,
            'options' => [
                'baseUrl' => env('ECOBANK_BASE_URL'),                       // sandbox
                'tokenPath' => env('ECOBANK_TOKEN_PATH', '/api/v2/token'),  // sandbox
                'subscriptionKey' => env('ECOBANK_SUBSCRIPTION_KEY'),       // Ocp-Apim-Subscription-Key
                'serviceCode' => env('ECOBANK_SERVICE_CODE'),               // sandbox (service Direct Debit)
                'clientId' => env('ECOBANK_CLIENT_ID'),
                'secretKey' => env('ECOBANK_SECRET_KEY'),
                'affiliateCode' => env('ECOBANK_AFFILIATE_CODE'),           // ex. code de la filiale du pays
                'sourceCode' => env('ECOBANK_SOURCE_CODE'),
                'ipAddress' => env('ECOBANK_SERVER_IP'),                    // IP publique déclarée chez Ecobank
                'userType' => env('ECOBANK_USER_TYPE', 'CUSTOMER'),         // sandbox
                'tokenCredentials' => array_filter([
                    'userId' => env('ECOBANK_TOKEN_USER'),
                    'password' => env('ECOBANK_TOKEN_PASSWORD'),
                ]),
                // requestType par opération (sandbox). Par défaut : nom de l'opération en SNAKE_CASE.
                'requestTypes' => array_filter(json_decode((string) env('ECOBANK_REQUEST_TYPES', '{}'), true) ?: []),
                'successCodes' => array_filter(explode(',', (string) env('ECOBANK_SUCCESS_CODES', '000,00,0,200'))),
            ],
            'currencies' => array_filter(explode(',', (string) env('ECOBANK_CURRENCIES', 'XOF,XAF,NGN,USD'))),
            'user_id_prefix' => env('ECOBANK_USER_ID_PREFIX', 'FGP'),
            'token_ttl' => (int) env('ECOBANK_TOKEN_TTL', 240),
        ],

        // Passerelle locale sans réseau : développement, démo et tests automatisés.
        'fake' => [
            'driver' => FakeDriver::class,
            'enabled' => (bool) env('PAYMENTS_FAKE_ENABLED', env('APP_ENV', 'production') !== 'production'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SEPA
    |--------------------------------------------------------------------------
    */
    'sepa' => [
        'creditor_id' => env('SEPA_CREDITOR_ID', 'FR00ZZZ000000'), // ICS fourni par la banque
        'creditor_name' => env('SEPA_CREDITOR_NAME', env('APP_NAME', 'FundGo')),
        'prenotification_days' => (int) env('SEPA_PRENOTIFICATION_DAYS', 14),
        'mandate_text_version' => '2026-11',
    ],

    /*
    |--------------------------------------------------------------------------
    | Relances (dunning)
    |--------------------------------------------------------------------------
    | Une nouvelle tentative après N jours, pour chaque entrée. Au-delà : le don
    | récurrent passe en « past_due » et le donateur est invité à régulariser.
    | Codes non relançables : mandat invalide / compte clos / opposition.
    */
    'dunning' => [
        'retry_after_days' => [3, 7],
        'non_retryable_codes' => [
            'AC01', 'AC04', 'AC06', 'AG01', 'MD01', 'MD07', 'MS02', 'SL01',
            'mandate_revoked', 'mandate_invalid', 'authentication_required', 'card_expired',
            // codes Stripe
            'expired_card', 'stolen_card', 'lost_card', 'account_closed', 'no_account', 'debit_not_authorized',
        ],
        // Codes qui invalident définitivement le moyen de paiement.
        'revoke_method_codes' => [
            'AC01', 'AC04', 'AC06', 'MD01', 'MD07', 'mandate_revoked', 'mandate_invalid', 'card_expired',
            'expired_card', 'stolen_card', 'lost_card', 'account_closed', 'no_account', 'debit_not_authorized',
        ],
    ],
];
