<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Support;

use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\UnsupportedCurrency;
use Alashqar\PaymentGateways\Money;

/**
 * An optional allow-list of currencies, checked before any request leaves the app.
 */
final readonly class SupportedCurrencies
{
    /**
     * @param  list<string>|null  $currencies  Null means "let the gateway decide".
     */
    public function __construct(private string $gateway, private ?array $currencies) {}

    public static function fromConfig(string $gateway, mixed $value): self
    {
        if ($value === null) {
            return new self($gateway, null);
        }

        $invalid = InvalidConfiguration::invalid($gateway, 'currencies', 'expected a list of ISO 4217 codes or null.');

        if (! is_array($value)) {
            throw $invalid;
        }

        $codes = [];

        foreach ($value as $code) {
            if (! is_string($code)) {
                throw $invalid;
            }

            $codes[] = strtoupper($code);
        }

        return new self($gateway, $codes);
    }

    /**
     * @throws UnsupportedCurrency
     */
    public function assertSupports(Money $money): void
    {
        if ($this->currencies !== null && ! in_array($money->currency, $this->currencies, true)) {
            throw UnsupportedCurrency::for($this->gateway, $money->currency);
        }
    }
}
