<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | The gateway returned by Payments::gateway() when no name is given.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client defaults
    |--------------------------------------------------------------------------
    |
    | Applied to every gateway unless a gateway overrides them in its own
    | "http" key. Retries only happen on connection errors (never on 4xx/5xx
    | responses), and every money-moving request carries an idempotency key
    | where the gateway supports one.
    |
    */

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retries' => 2,
        'retry_delay_ms' => 250,
    ],

    'gateways' => [

        'stripe' => [
            'secret_key' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            // Seconds a signed webhook stays valid; Stripe's own libraries default to 300.
            'webhook_tolerance' => 300,
            // Pin a Stripe API version, or null to use your account's default.
            'api_version' => env('STRIPE_API_VERSION'),
            // Null lets Stripe validate the currency; a list restricts it locally.
            'currencies' => null,
        ],

    ],

];
