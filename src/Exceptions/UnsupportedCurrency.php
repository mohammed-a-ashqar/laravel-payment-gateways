<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

final class UnsupportedCurrency extends GatewayException
{
    public static function for(string $gateway, string $currency): self
    {
        return new self("The [{$gateway}] gateway does not accept {$currency}.", $gateway);
    }
}
