<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Support;

use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;

/**
 * Reads a gateway's config array with clear errors for missing credentials,
 * instead of letting a null API key reach the gateway as an opaque 401.
 */
final readonly class ConfigReader
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(private string $gateway, private array $config) {}

    public function required(string $key): string
    {
        $value = $this->optional($key);

        if ($value === null) {
            throw InvalidConfiguration::missing($this->gateway, $key);
        }

        return $value;
    }

    public function optional(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        if ($value !== null && ! is_string($value)) {
            throw InvalidConfiguration::invalid($this->gateway, $key, 'expected a string.');
        }

        return $value === null || trim($value) === '' ? $default : trim($value);
    }

    public function string(string $key, string $default): string
    {
        return $this->optional($key) ?? $default;
    }

    public function integer(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw InvalidConfiguration::invalid($this->gateway, $key, 'expected an integer.');
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function oneOf(string $key, array $allowed, string $default): string
    {
        $value = $this->string($key, $default);

        if (! in_array($value, $allowed, true)) {
            throw InvalidConfiguration::invalid($this->gateway, $key, 'expected one of: '.implode(', ', $allowed).'.');
        }

        return $value;
    }

    public function currencies(): SupportedCurrencies
    {
        return SupportedCurrencies::fromConfig($this->gateway, $this->config['currencies'] ?? null);
    }
}
