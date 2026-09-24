<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Gateways\PayPal\PayPalGateway;
use Alashqar\PaymentGateways\Gateways\PayPal\PayPalTokenProvider;
use Alashqar\PaymentGateways\Gateways\Stripe\StripeGateway;
use Alashqar\PaymentGateways\Gateways\WaafiPay\WaafiPayCredentials;
use Alashqar\PaymentGateways\Gateways\WaafiPay\WaafiPayGateway;
use Alashqar\PaymentGateways\Gateways\WaafiPay\WaafiPaySignature;
use Alashqar\PaymentGateways\Support\ConfigReader;
use Alashqar\PaymentGateways\Support\GatewayClient;
use Alashqar\PaymentGateways\Support\HttpOptions;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves and caches gateway drivers.
 *
 * Custom drivers registered with extend() receive the container and their own
 * "payment-gateways.gateways.{name}" config array:
 *
 *     Payments::extend('acme', fn ($app, array $config) => new AcmeGateway($config));
 */
class PaymentManager extends Manager
{
    public function gateway(?string $name = null): Gateway
    {
        $driver = $this->driver($name);

        if (! $driver instanceof Gateway) {
            throw new InvalidArgumentException(sprintf(
                'Payment driver [%s] must implement %s.',
                $name ?? $this->getDefaultDriver(),
                Gateway::class,
            ));
        }

        return $driver;
    }

    public function getDefaultDriver(): string
    {
        $default = $this->config->get('payment-gateways.default');

        if (! is_string($default) || $default === '') {
            throw new InvalidArgumentException('No default payment gateway is configured.');
        }

        return $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function gatewayConfig(string $name): array
    {
        $config = $this->config->get("payment-gateways.gateways.{$name}", []);

        if (! is_array($config)) {
            throw InvalidConfiguration::invalid($name, "payment-gateways.gateways.{$name}", 'expected an array.');
        }

        return $config;
    }

    /**
     * An HTTP client configured with the global settings merged with the gateway's
     * own "http" overrides. Custom drivers can use it to get the same timeout and
     * retry behaviour as the built-in ones.
     */
    public function client(string $name): GatewayClient
    {
        $global = $this->config->get('payment-gateways.http', []);
        $local = $this->gatewayConfig($name)['http'] ?? [];

        $options = array_merge(is_array($global) ? $global : [], is_array($local) ? $local : []);

        return new GatewayClient(
            $this->container->make(HttpFactory::class),
            $name,
            HttpOptions::fromArray($name, $options),
        );
    }

    protected function createStripeDriver(): StripeGateway
    {
        $config = $this->configReader('stripe');

        return new StripeGateway(
            client: $this->client('stripe'),
            secretKey: $config->required('secret_key'),
            currencies: $config->currencies(),
            webhookSecret: $config->optional('webhook_secret'),
            webhookTolerance: $config->integer('webhook_tolerance', 300),
            apiVersion: $config->optional('api_version'),
            baseUrl: $config->optional('base_url', 'https://api.stripe.com') ?? 'https://api.stripe.com',
        );
    }

    protected function createPaypalDriver(): PayPalGateway
    {
        $config = $this->configReader('paypal');

        $baseUrl = $config->optional('base_url') ?? match ($config->oneOf('mode', ['sandbox', 'live'], 'sandbox')) {
            'live' => PayPalGateway::LIVE_URL,
            default => PayPalGateway::SANDBOX_URL,
        };

        $client = $this->client('paypal');

        return new PayPalGateway(
            client: $client,
            tokens: new PayPalTokenProvider(
                $client,
                $this->container->make(CacheFactory::class)->store($config->optional('cache_store')),
                $config->required('client_id'),
                $config->required('client_secret'),
                $baseUrl,
            ),
            currencies: $config->currencies(),
            baseUrl: $baseUrl,
            webhookId: $config->optional('webhook_id'),
            brandName: $config->optional('brand_name'),
        );
    }

    protected function createWaafipayDriver(): WaafiPayGateway
    {
        $config = $this->configReader('waafipay');
        $webhookSecret = $config->optional('webhook_secret');

        return new WaafiPayGateway(
            client: $this->client('waafipay'),
            credentials: new WaafiPayCredentials(
                $config->required('merchant_uid'),
                $config->required('api_user_id'),
                $config->required('api_key'),
            ),
            currencies: $config->currencies(),
            endpoint: $config->optional('base_url') ?? match ($config->oneOf('mode', ['sandbox', 'live'], 'sandbox')) {
                'live' => WaafiPayGateway::LIVE_URL,
                default => WaafiPayGateway::SANDBOX_URL,
            },
            paymentMethod: $config->optional('payment_method', 'MWALLET_ACCOUNT') ?? 'MWALLET_ACCOUNT',
            webhookSignature: $webhookSecret === null
                ? null
                : new WaafiPaySignature($webhookSecret, $config->integer('webhook_tolerance', 300)),
        );
    }

    protected function configReader(string $name): ConfigReader
    {
        return new ConfigReader($name, $this->gatewayConfig($name));
    }

    /**
     * @param  string  $driver
     */
    protected function callCustomCreator($driver): mixed
    {
        $creator = $this->customCreators[$driver];

        if (! is_callable($creator)) {
            throw new InvalidArgumentException("The creator registered for payment driver [{$driver}] is not callable.");
        }

        return $creator($this->container, $this->gatewayConfig($driver));
    }
}
