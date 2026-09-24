<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

use InvalidArgumentException;

final class InvalidConfiguration extends InvalidArgumentException
{
    public static function missing(string $gateway, string $key): self
    {
        return new self("The [{$gateway}] gateway is missing the [{$key}] configuration value.");
    }

    public static function invalid(string $gateway, string $key, string $reason): self
    {
        return new self("The [{$gateway}] gateway has an invalid [{$key}] value: {$reason}");
    }
}
