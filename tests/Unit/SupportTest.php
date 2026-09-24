<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\UnsupportedCurrency;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\ConfigReader;
use Alashqar\PaymentGateways\Support\Payload;
use Alashqar\PaymentGateways\Support\SupportedCurrencies;

it('reads untrusted payloads without type errors', function () {
    $payload = new Payload([
        'id' => 'pi_1',
        'amount' => 1050,
        'numeric' => '42',
        'nested' => ['flag' => true, 'list' => [['id' => 'cap_1']]],
        'weird' => ['not' => 'a string'],
    ]);

    expect($payload->string('id'))->toBe('pi_1')
        ->and($payload->string('amount'))->toBe('1050')
        ->and($payload->int('numeric'))->toBe(42)
        ->and($payload->int('id'))->toBeNull()
        ->and($payload->string('weird'))->toBeNull()
        ->and($payload->bool('nested.flag'))->toBeTrue()
        ->and($payload->string('nested.list.0.id'))->toBe('cap_1')
        ->and($payload->get('missing')->isEmpty())->toBeTrue()
        ->and(Payload::fromJson('not json')->all())->toBe([]);
});

it('requires credentials to be present', function () {
    (new ConfigReader('stripe', ['secret' => '  ']))->required('secret');
})->throws(InvalidConfiguration::class, 'The [stripe] gateway is missing the [secret] configuration value.');

it('validates enumerated config values', function () {
    $reader = new ConfigReader('paypal', ['mode' => 'production']);

    $reader->oneOf('mode', ['sandbox', 'live'], 'sandbox');
})->throws(InvalidConfiguration::class, 'expected one of: sandbox, live.');

it('enforces a currency allow-list when one is configured', function () {
    $currencies = SupportedCurrencies::fromConfig('waafipay', ['usd']);

    $currencies->assertSupports(Money::of(100, 'USD'));

    expect(fn () => $currencies->assertSupports(Money::of(100, 'EUR')))
        ->toThrow(UnsupportedCurrency::class, 'The [waafipay] gateway does not accept EUR.');

    SupportedCurrencies::fromConfig('stripe', null)->assertSupports(Money::of(100, 'XOF'));
});

it('rejects a malformed currency allow-list', function () {
    SupportedCurrencies::fromConfig('stripe', 'USD');
})->throws(InvalidConfiguration::class, 'currencies');
