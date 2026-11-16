<?php

use App\Payments\Drivers\FakeDriver;
use App\Payments\Drivers\MollieDriver;
use App\Payments\Drivers\StripeDriver;
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

    'routing' => [
        'sepa_debit' => env('PAYMENTS_SEPA_GATEWAY'),
        'card' => env('PAYMENTS_CARD_GATEWAY'),
    ],

    'currency' => 'EUR',
    'min_amount_cents' => (int) env('DONATION_MIN_CENTS', 100),
    'max_amount_cents' => (int) env('DONATION_MAX_CENTS', 1_000_000),
    'suggested_amounts' => [10, 20, 50, 100],

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
