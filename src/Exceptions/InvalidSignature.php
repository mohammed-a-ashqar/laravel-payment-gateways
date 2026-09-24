<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

final class InvalidSignature extends GatewayException
{
    public static function because(string $gateway, string $reason): self
    {
        return new self("Webhook signature rejected: {$reason}", $gateway);
    }
}
