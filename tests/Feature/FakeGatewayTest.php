<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Data\Customer;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Exceptions\InvalidStatusTransition;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

function checkout(string $gateway, int $cents): PaymentStatus
{
    return Payments::gateway($gateway)->createPayment(new PaymentRequest(
        Money::of($cents, 'USD'),
        'order_'.$cents,
        customer: new Customer(email: 'buyer@example.com'),
        returnUrl: 'https://shop.test/return',
    ))->status;
}

it('replaces every gateway without touching the network', function () {
    Http::fake();
    $payments = Payments::fake();

    expect(checkout('stripe', 2500))->toBe(PaymentStatus::Succeeded)
        ->and(checkout('paypal', 1000))->toBe(PaymentStatus::Succeeded)
        ->and(app(PaymentManager::class)->gateway('waafipay'))->toBe($payments);

    $payments->assertChargedTimes(2);
    $payments->assertCharged(Money::of(2500, 'USD'));
    $payments->assertCharged(fn (PaymentRequest $request) => $request->reference === 'order_1000');
    $payments->assertNotCharged(Money::of(9999, 'USD'));
    Http::assertNothingSent();
});

it('fails assertions that do not hold', function (Closure $assertion) {
    $payments = Payments::fake();
    checkout('stripe', 2500);

    $assertion($payments);
})->throws(AssertionFailedError::class)->with([
    'wrong amount' => fn ($payments) => $payments->assertCharged(Money::of(1, 'USD')),
    'wrong callback' => fn ($payments) => $payments->assertCharged(fn () => false),
    'nothing charged' => fn ($payments) => $payments->assertNothingCharged(),
    'not charged' => fn ($payments) => $payments->assertNotCharged(Money::of(2500, 'USD')),
    'times' => fn ($payments) => $payments->assertChargedTimes(3),
    'refunded' => fn ($payments) => $payments->assertRefunded(),
]);

it('simulates declines, redirects and outages', function () {
    $payments = Payments::fake();

    $payments->willFail('insufficient_funds');
    $declined = Payments::gateway()->createPayment(new PaymentRequest(Money::of(100, 'USD'), 'o1'));

    $payments->willRequireAction();
    $redirect = Payments::gateway()->createPayment(new PaymentRequest(Money::of(100, 'USD'), 'o2'));

    $payments->willThrow(new GatewayUnavailable('down', 'fake'));

    expect($declined->status)->toBe(PaymentStatus::Failed)
        ->and($declined->failureReason)->toBe('insufficient_funds')
        ->and($redirect->requiresRedirect())->toBeTrue()
        ->and(fn () => Payments::gateway()->createPayment(new PaymentRequest(Money::of(100, 'USD'), 'o3')))
        ->toThrow(GatewayUnavailable::class);

    $payments->willSucceed();
    expect(checkout('stripe', 100))->toBe(PaymentStatus::Succeeded);
});

it('tracks partial and full refunds', function () {
    $payments = Payments::fake();
    $payment = Payments::gateway()->createPayment(new PaymentRequest(Money::of(2500, 'USD'), 'o1'));
    $id = (string) $payment->transactionId;

    Payments::gateway()->refund($id, Money::of(1000, 'USD'));
    expect(Payments::gateway()->find($id)->status)->toBe(PaymentStatus::PartiallyRefunded);

    $rest = Payments::gateway()->refund($id);
    expect($rest->amount?->equals(Money::of(1500, 'USD')))->toBeTrue()
        ->and(Payments::gateway()->find($id)->status)->toBe(PaymentStatus::Refunded);

    $payments->assertRefunded($id);
    $payments->assertRefunded(fn (string $transactionId, Money $amount) => $amount->equals(Money::of(1000, 'USD')));
});

it('behaves like a real gateway when refunds are impossible', function () {
    $payments = Payments::fake()->willFail();
    $failed = Payments::gateway()->createPayment(new PaymentRequest(Money::of(2500, 'USD'), 'o1'));

    expect(fn () => Payments::gateway()->refund((string) $failed->transactionId))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => Payments::gateway()->refund('missing'))->toThrow(GatewayException::class, 'Unknown fake transaction');

    $payments->willSucceed();
    $paid = Payments::gateway()->createPayment(new PaymentRequest(Money::of(2500, 'USD'), 'o2'));

    expect(fn () => Payments::gateway()->refund((string) $paid->transactionId, Money::of(3000, 'USD')))
        ->toThrow(GatewayException::class, 'exceed');

    $payments->assertNothingRefunded();
});

it('supports authorize, capture and void flows', function () {
    $payments = Payments::fake();

    $first = $payments->authorize(new PaymentRequest(Money::of(500, 'USD'), 'hold_1'));
    $second = $payments->authorize(new PaymentRequest(Money::of(700, 'USD'), 'hold_2'));

    expect($first->status)->toBe(PaymentStatus::Authorized)
        ->and($payments->capture((string) $first->transactionId)->status)->toBe(PaymentStatus::Succeeded)
        ->and($payments->void((string) $second->transactionId)->status)->toBe(PaymentStatus::Canceled);

    $payments->assertAuthorized(Money::of(700, 'USD'));
    $payments->assertNothingCharged();
});

it('parses unsigned webhook bodies for application tests', function () {
    $payments = Payments::fake();

    $event = $payments->parseWebhook(Request::create('/', 'POST', content: json_encode([
        'id' => 'evt_1',
        'type' => 'payment.succeeded',
        'status' => 'succeeded',
        'transaction_id' => 'fake_1',
        'reference' => 'order_1',
    ])));

    expect($event->id)->toBe('evt_1')
        ->and($event->status)->toBe(PaymentStatus::Succeeded)
        ->and($event->reference)->toBe('order_1');
});
