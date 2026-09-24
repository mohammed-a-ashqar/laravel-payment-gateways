<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Support;

use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;

final readonly class HttpOptions
{
    public function __construct(
        public int $timeout = 30,
        public int $connectTimeout = 10,
        public int $retries = 2,
        public int $retryDelayMs = 250,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(string $gateway, array $config): self
    {
        $defaults = new self;

        return new self(
            timeout: self::integer($gateway, $config, 'timeout', $defaults->timeout),
            connectTimeout: self::integer($gateway, $config, 'connect_timeout', $defaults->connectTimeout),
            retries: self::integer($gateway, $config, 'retries', $defaults->retries),
            retryDelayMs: self::integer($gateway, $config, 'retry_delay_ms', $defaults->retryDelayMs),
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function integer(string $gateway, array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0) {
            throw InvalidConfiguration::invalid($gateway, "http.{$key}", 'expected a non-negative integer.');
        }

        return $value;
    }
}
