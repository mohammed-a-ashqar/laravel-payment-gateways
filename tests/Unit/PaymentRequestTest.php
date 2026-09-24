<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Data\Customer;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Money;

it('accepts a well formed request', function () {
    $request = new PaymentRequest(
        amount: Money::of(2500, 'USD'),
        reference: 'order_1001',
        description: 'Order #1001',
        customer: new Customer(email: 'buyer@example.com', phone: '252611111111'),
        returnUrl: 'https://shop.test/return',
        cancelUrl: 'https://shop.test/cancel',
        metadata: ['cart' => '42'],
    );

    expect($request->reference)->toBe('order_1001')
        ->and($request->customer?->phone)->toBe('252611111111');
});

it('rejects non-positive amounts', function (int $amount) {
    new PaymentRequest(Money::of($amount, 'USD'), 'order_1');
})->throws(InvalidArgumentException::class, 'greater than zero')->with([0, -100]);

it('rejects references that some gateway would refuse', function (string $reference) {
    new PaymentRequest(Money::of(100, 'USD'), $reference);
})->throws(InvalidArgumentException::class, 'payment reference')->with([
    'empty' => '',
    'spaces' => 'order 1',
    'slash' => 'order/1',
    'too long' => str_repeat('a', 128),
]);

it('rejects invalid urls and emails', function (Closure $build) {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'return url' => fn () => new PaymentRequest(Money::of(100, 'USD'), 'o1', returnUrl: 'not a url'),
    'cancel url' => fn () => new PaymentRequest(Money::of(100, 'USD'), 'o1', cancelUrl: '/relative'),
    'email' => fn () => new Customer(email: 'nope'),
]);

it('rejects non-string metadata', function () {
    new PaymentRequest(Money::of(100, 'USD'), 'o1', metadata: ['qty' => 3]);
})->throws(InvalidArgumentException::class, 'map of strings to strings');

it('only asks for a redirect while the payer has something to do', function () {
    $pending = new PaymentResult(PaymentStatus::RequiresAction, 'cs_1', redirectUrl: 'https://pay.test');
    $paid = new PaymentResult(PaymentStatus::Succeeded, 'cs_1', redirectUrl: 'https://pay.test');

    expect($pending->requiresRedirect())->toBeTrue()
        ->and($paid->requiresRedirect())->toBeFalse()
        ->and($paid->isPaid())->toBeTrue();
});
