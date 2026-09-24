<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\PaymentManager;
use Alashqar\PaymentGateways\Tests\Fixtures\InMemoryGateway;
use Illuminate\Contracts\Container\Container;

it('registers the manager as a singleton', function () {
    expect(app(PaymentManager::class))->toBe(app(PaymentManager::class));
});

it('merges the package configuration', function () {
    expect(config('payment-gateways.http.timeout'))->toBe(30);
});

it('lets applications register their own drivers', function () {
    config()->set('payment-gateways.gateways.in-memory', ['api_key' => 'secret']);

    Payments::extend('in-memory', function (Container $app, array $config) {
        expect($app)->toBeInstanceOf(Container::class);

        return new InMemoryGateway($config);
    });

    $gateway = Payments::gateway('in-memory');
    $result = $gateway->createPayment(new PaymentRequest(Money::of(100, 'USD'), 'order_1'));

    expect($gateway)->toBeInstanceOf(InMemoryGateway::class)
        ->and($gateway->config)->toBe(['api_key' => 'secret'])
        ->and($result->transactionId)->toBe('mem_order_1')
        ->and(Payments::gateway('in-memory'))->toBe($gateway);
});

it('uses the configured default gateway', function () {
    config()->set('payment-gateways.default', 'in-memory');
    Payments::extend('in-memory', fn () => new InMemoryGateway);

    expect(Payments::gateway())->toBeInstanceOf(InMemoryGateway::class);
});

it('fails loudly for unknown drivers', function () {
    Payments::gateway('bitcoin-by-carrier-pigeon');
})->throws(InvalidArgumentException::class, 'Driver [bitcoin-by-carrier-pigeon] not supported.');

it('refuses drivers that do not implement the gateway contract', function () {
    Payments::extend('broken', fn () => new stdClass);

    Payments::gateway('broken');
})->throws(InvalidArgumentException::class, 'must implement');

it('requires a default gateway name', function () {
    config()->set('payment-gateways.default', null);

    Payments::gateway();
})->throws(InvalidArgumentException::class, 'No default payment gateway is configured.');
