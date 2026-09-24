<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\PayPal;

use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\Payload;
use InvalidArgumentException;

/**
 * Converts between Money and PayPal's {currency_code, value} amount objects.
 */
final class PayPalAmount
{
    /**
     * PayPal rejects decimals for these even though ISO 4217 defines two minor digits.
     *
     * @see https://developer.paypal.com/reference/currency-codes/
     */
    private const WHOLE_UNITS_ONLY = ['HUF', 'TWD'];

    /**
     * @return array{currency_code: string, value: string}
     */
    public static function toPayPal(Money $money): array
    {
        if (in_array($money->currency, self::WHOLE_UNITS_ONLY, true)) {
            if ($money->amount % 100 !== 0) {
                throw new InvalidArgumentException("PayPal only accepts whole {$money->currency} amounts.");
            }

            return ['currency_code' => $money->currency, 'value' => (string) intdiv($money->amount, 100)];
        }

        return ['currency_code' => $money->currency, 'value' => $money->toDecimal()];
    }

    public static function fromPayPal(Payload $amount): ?Money
    {
        $currency = $amount->string('currency_code');
        $value = $amount->string('value');

        return $currency !== null && $value !== null ? Money::fromDecimal($value, $currency) : null;
    }
}
