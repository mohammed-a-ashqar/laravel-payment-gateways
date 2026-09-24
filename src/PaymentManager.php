<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Gateways\Stripe\StripeGateway;
use Alashqar\PaymentGateways\Support\ConfigReader;
use Alashqar\PaymentGateways\Support\GatewayClient;
use Alashqar\PaymentGateways\Support\HttpOptions;
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

    protected function configReader(string $name): ConfigReader
    {
        return new ConfigReader($name, $this->gatewayConfig($name));
    }

    /**
     * @param  string  $driver
     */
    protected function callCustomCreator($driver): mixed
    {
        return $this->customCreators[$driver]($this->container, $this->gatewayConfig($driver));
    }
}
