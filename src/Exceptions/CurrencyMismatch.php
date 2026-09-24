<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

use Alashqar\PaymentGateways\Money;
use InvalidArgumentException;

final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(Money $left, Money $right): self
    {
        return new self("Cannot combine {$left->currency} with {$right->currency}.");
    }
}
