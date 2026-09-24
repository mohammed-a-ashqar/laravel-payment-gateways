<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use LogicException;

final class InvalidStatusTransition extends LogicException
{
    public static function from(PaymentStatus $current, PaymentStatus $next): self
    {
        return new self("A payment cannot move from [{$current->value}] to [{$next->value}].");
    }
}
