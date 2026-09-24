<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
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
     * @return array<string, mixed>
     */
    public function gatewayConfig(string $name): array
    {
        $config = $this->config->get("payment-gateways.gateways.{$name}", []);

        if (! is_array($config)) {
            throw InvalidConfiguration::invalid($name, "payment-gateways.gateways.{$name}", 'expected an array.');
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * The global HTTP settings merged with the gateway's own "http" overrides.
     *
     * @return array<string, mixed>
     */
    protected function httpConfig(string $name): array
    {
        $global = $this->config->get('payment-gateways.http', []);
        $local = $this->gatewayConfig($name)['http'] ?? [];

        return array_merge(is_array($global) ? $global : [], is_array($local) ? $local : []);
    }

    /**
     * @param  string  $driver
     */
    protected function callCustomCreator($driver): mixed
    {
        return $this->customCreators[$driver]($this->container, $this->gatewayConfig($driver));
    }
}
