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

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | When enabled, POST {path}/{gateway} verifies the incoming webhook with
    | the gateway's driver and dispatches PaymentSucceeded, PaymentFailed and
    | PaymentRefunded events. The route sits outside the "web" group, so no
    | CSRF token is needed. Processed event ids are remembered for replay_ttl
    | seconds so redelivered events are handled once.
    |
    */

    'webhooks' => [
        'enabled' => env('PAYMENT_WEBHOOKS_ENABLED', true),
        'path' => 'payment-gateways/webhooks',
        'middleware' => [],
        'replay_ttl' => 60 * 60 * 24 * 7,
        // Cache store used for replay protection; null uses the default store.
        'cache_store' => null,
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

        'paypal' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            // "sandbox" or "live".
            'mode' => env('PAYPAL_MODE', 'sandbox'),
            // The id of the webhook you registered in the PayPal developer dashboard.
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'brand_name' => env('PAYPAL_BRAND_NAME'),
            // Cache store for OAuth tokens; null uses the default store.
            'cache_store' => null,
            // https://developer.paypal.com/reference/currency-codes/
            'currencies' => [
                'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR',
                'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
            ],
        ],

        'waafipay' => [
            'merchant_uid' => env('WAAFIPAY_MERCHANT_UID'),
            'api_user_id' => env('WAAFIPAY_API_USER_ID'),
            'api_key' => env('WAAFIPAY_API_KEY'),
            // "sandbox" (https://sandbox.waafipay.com/asm) or "live" (https://api.waafipay.net/asm).
            'mode' => env('WAAFIPAY_MODE', 'sandbox'),
            'payment_method' => 'MWALLET_ACCOUNT',
            // Only needed for Hosted Payment Page webhooks.
            'webhook_secret' => env('WAAFIPAY_WEBHOOK_SECRET'),
            'webhook_tolerance' => 300,
            'currencies' => ['USD'],
            'http' => [
                // The payer approves the charge on their phone, so allow a generous timeout.
                'timeout' => 90,
                // WaafiPay documents no idempotency key: a retried purchase could charge
                // twice, so connection errors are surfaced instead of retried.
                'retries' => 0,
            ],
        ],

    ],

];
