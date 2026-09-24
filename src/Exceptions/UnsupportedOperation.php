<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

final class UnsupportedOperation extends GatewayException
{
    public static function for(string $gateway, string $operation): self
    {
        return new self("The [{$gateway}] gateway does not support {$operation}.", $gateway);
    }
}
