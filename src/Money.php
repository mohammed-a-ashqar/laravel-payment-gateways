<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways;

use Alashqar\PaymentGateways\Exceptions\CurrencyMismatch;
use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

/**
 * An immutable amount of money stored in the currency's minor unit (cents, fils, yen...).
 *
 * Integers are used on purpose: floats cannot represent most decimal amounts exactly,
 * and every gateway in this package ultimately needs either an integer or an exact
 * decimal string.
 */
final readonly class Money implements JsonSerializable
{
    /** ISO 4217 currencies whose minor unit is not 1/100. */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3,
        'TND' => 3,
    ];

    public string $currency;

    public function __construct(public int $amount, string $currency)
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("[{$currency}] is not a valid ISO 4217 currency code.");
        }

        $this->currency = $currency;
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    /**
     * Build Money from a decimal string such as "10.50".
     *
     * Strings are required instead of floats so that "0.1 + 0.2" style precision loss
     * can never leak into a charge. Extra precision is rejected rather than rounded.
     */
    public static function fromDecimal(string $decimal, string $currency): self
    {
        $decimal = trim($decimal);

        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException("[{$decimal}] is not a valid decimal amount.");
        }

        $exponent = self::exponentFor($currency);
        $fraction = $matches[3] ?? '';

        if (strlen(rtrim($fraction, '0')) > $exponent) {
            throw new InvalidArgumentException(
                "[{$decimal}] has more precision than {$currency} allows ({$exponent} decimal places)."
            );
        }

        $digits = ltrim($matches[2].str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0');

        if ($digits === '') {
            $digits = '0';
        }

        if (strlen($digits) > 18) {
            throw new OverflowException("[{$decimal}] is too large to be represented.");
        }

        $amount = (int) $digits;

        return new self($matches[1] === '-' ? -$amount : $amount, $currency);
    }

    public static function exponentFor(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public function exponent(): int
    {
        return self::exponentFor($this->currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->guard($this->amount + $other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->guard($this->amount - $other->amount), $this->currency);
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    public function equals(self $other): bool
    {
        return $this->isSameCurrency($other) && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * The exact decimal representation, e.g. 1050 USD => "10.50", 500 JPY => "500".
     */
    public function toDecimal(): string
    {
        $exponent = $this->exponent();
        $digits = (string) abs($this->amount);
        $sign = $this->amount < 0 ? '-' : '';

        if ($exponent === 0) {
            return $sign.$digits;
        }

        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    /**
     * A locale-neutral human representation, e.g. "USD 1,234.50".
     */
    public function format(): string
    {
        $decimal = ltrim($this->toDecimal(), '-');
        $sign = $this->isNegative() ? '-' : '';
        $point = strpos($decimal, '.');
        $whole = $point === false ? $decimal : substr($decimal, 0, $point);
        $fraction = $point === false ? '' : substr($decimal, $point);

        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return $this->currency.' '.$sign.$grouped.$fraction;
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->isSameCurrency($other)) {
            throw CurrencyMismatch::between($this, $other);
        }
    }

    private function guard(int|float $result): int
    {
        if (! is_int($result)) {
            throw new OverflowException('Money arithmetic overflowed the integer range.');
        }

        return $result;
    }
}
