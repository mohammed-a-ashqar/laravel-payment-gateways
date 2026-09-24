<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Exceptions\CurrencyMismatch;
use Alashqar\PaymentGateways\Money;

it('normalises and validates the currency code', function () {
    expect(Money::of(100, 'usd')->currency)->toBe('USD');

    Money::of(100, 'US');
})->throws(InvalidArgumentException::class, 'not a valid ISO 4217 currency code');

it('adds and subtracts amounts of the same currency', function () {
    $total = Money::of(1050, 'USD')->plus(Money::of(250, 'USD'))->minus(Money::of(100, 'USD'));

    expect($total->amount)->toBe(1200)
        ->and($total->currency)->toBe('USD');
});

it('never mutates the original instance', function () {
    $original = Money::of(1000, 'USD');
    $original->plus(Money::of(1, 'USD'));

    expect($original->amount)->toBe(1000);
});

it('refuses to combine different currencies', function (Closure $operation) {
    $operation(Money::of(100, 'USD'), Money::of(100, 'EUR'));
})->throws(CurrencyMismatch::class, 'Cannot combine USD with EUR.')->with([
    'plus' => fn (Money $a, Money $b) => $a->plus($b),
    'minus' => fn (Money $a, Money $b) => $a->minus($b),
    'greaterThan' => fn (Money $a, Money $b) => $a->greaterThan($b),
    'lessThan' => fn (Money $a, Money $b) => $a->lessThan($b),
]);

it('guards against integer overflow', function () {
    Money::of(PHP_INT_MAX, 'USD')->plus(Money::of(1, 'USD'));
})->throws(OverflowException::class);

it('compares amounts', function () {
    $ten = Money::of(1000, 'USD');

    expect($ten->greaterThan(Money::of(999, 'USD')))->toBeTrue()
        ->and($ten->lessThan(Money::of(1001, 'USD')))->toBeTrue()
        ->and($ten->equals(Money::of(1000, 'USD')))->toBeTrue()
        ->and($ten->equals(Money::of(1000, 'EUR')))->toBeFalse()
        ->and(Money::of(0, 'USD')->isZero())->toBeTrue()
        ->and(Money::of(-5, 'USD')->isNegative())->toBeTrue()
        ->and($ten->isPositive())->toBeTrue();
});

it('converts to an exact decimal string using the currency exponent', function (Money $money, string $expected) {
    expect($money->toDecimal())->toBe($expected);
})->with([
    'two decimals' => [Money::of(1050, 'USD'), '10.50'],
    'sub unit' => [Money::of(5, 'USD'), '0.05'],
    'zero decimal currency' => [Money::of(500, 'JPY'), '500'],
    'three decimal currency' => [Money::of(1500, 'KWD'), '1.500'],
    'negative' => [Money::of(-1050, 'EUR'), '-10.50'],
]);

it('parses decimal strings without floating point drift', function (string $decimal, string $currency, int $expected) {
    expect(Money::fromDecimal($decimal, $currency)->amount)->toBe($expected);
})->with([
    ['10.50', 'USD', 1050],
    ['0.1', 'USD', 10],
    ['19.99', 'USD', 1999],
    ['7', 'USD', 700],
    ['1.500', 'KWD', 1500],
    ['1200', 'JPY', 1200],
    ['10.500', 'USD', 1050],
    ['-3.25', 'EUR', -325],
]);

it('rejects decimals with more precision than the currency supports', function () {
    Money::fromDecimal('10.237', 'USD');
})->throws(InvalidArgumentException::class, 'more precision than USD allows');

it('rejects malformed decimal strings', function (string $input) {
    Money::fromDecimal($input, 'USD');
})->throws(InvalidArgumentException::class)->with(['', 'abc', '1,000.00', '1e3', '.5']);

it('formats amounts for humans', function () {
    expect(Money::of(123450, 'USD')->format())->toBe('USD 1,234.50')
        ->and(Money::of(-99, 'EUR')->format())->toBe('EUR -0.99')
        ->and(Money::of(1000000, 'JPY')->format())->toBe('JPY 1,000,000');
});

it('serialises to json', function () {
    expect(json_encode(Money::of(1050, 'USD')))->toBe('{"amount":1050,"currency":"USD"}');
});
