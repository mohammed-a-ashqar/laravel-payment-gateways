<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Data;

use InvalidArgumentException;

final readonly class Customer
{
    /**
     * @param  string|null  $phone  In international format; WaafiPay uses it as the wallet account number.
     */
    public function __construct(
        public ?string $email = null,
        public ?string $name = null,
        public ?string $phone = null,
    ) {
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("[{$email}] is not a valid email address.");
        }
    }
}
