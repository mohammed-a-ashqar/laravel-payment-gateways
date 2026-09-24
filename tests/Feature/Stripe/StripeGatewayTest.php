<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Data\Customer;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\UnsupportedCurrency;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payment-gateways.http.retry_delay_ms', 0);
    config()->set('payment-gateways.gateways.stripe.secret_key', 'sk_test_123');
});

function stripeRequest(): PaymentRequest
{
    return new PaymentRequest(
        amount: Money::of(2500, 'USD'),
        reference: 'order_1001',
        description: 'Order #1001',
        customer: new Customer(email: 'buyer@example.com'),
        returnUrl: 'https://shop.test/thanks?session_id={CHECKOUT_SESSION_ID}',
        cancelUrl: 'https://shop.test/cart',
        metadata: ['cart_id' => '42'],
    );
}

it('creates a hosted checkout session with an idempotency key', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(fixture('stripe/checkout-session-open.json'))]);

    $result = Payments::gateway('stripe')->createPayment(stripeRequest());

    expect($result->status)->toBe(PaymentStatus::RequiresAction)
        ->and($result->transactionId)->toBe('cs_test_a1b2c3d4e5f6g7h8i9j0')
        ->and($result->redirectUrl)->toStartWith('https://checkout.stripe.com/c/pay/cs_test_')
        ->and($result->requiresRedirect())->toBeTrue()
        ->and($result->amount?->equals(Money::of(2500, 'USD')))->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer sk_test_123')
            && $request->hasHeader('Idempotency-Key', 'checkout-session-order_1001')
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && formBody($request) === [
                'mode' => 'payment',
                'success_url' => 'https://shop.test/thanks?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => 'https://shop.test/cart',
                'client_reference_id' => 'order_1001',
                'customer_email' => 'buyer@example.com',
                'line_items' => [[
                    'quantity' => '1',
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => '2500',
                        'product_data' => ['name' => 'Order #1001'],
                    ],
                ]],
                'metadata' => ['cart_id' => '42', 'reference' => 'order_1001'],
                'payment_intent_data' => [
                    'description' => 'Order #1001',
                    'metadata' => ['cart_id' => '42', 'reference' => 'order_1001'],
                ],
            ];
    });
});

it('pins the api version when configured', function () {
    config()->set('payment-gateways.gateways.stripe.api_version', '2024-06-20');
    Http::fake(['api.stripe.com/*' => Http::response(fixture('stripe/checkout-session-open.json'))]);

    Payments::gateway('stripe')->createPayment(stripeRequest());

    Http::assertSent(fn (Request $request) => $request->hasHeader('Stripe-Version', '2024-06-20'));
});

it('needs a return url for hosted checkout', function () {
    Http::fake();

    Payments::gateway('stripe')->createPayment(new PaymentRequest(Money::of(100, 'USD'), 'order_1'));
})->throws(InvalidArgumentException::class, 'returnUrl');

it('refuses currencies outside the configured allow-list before calling stripe', function () {
    config()->set('payment-gateways.gateways.stripe.currencies', ['EUR']);
    Http::fake();

    expect(fn () => Payments::gateway('stripe')->createPayment(stripeRequest()))->toThrow(UnsupportedCurrency::class);

    Http::assertNothingSent();
});

it('reports a paid session as succeeded', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/cs_test_a1b2c3d4e5f6g7h8i9j0' => Http::response(fixture('stripe/checkout-session-complete.json')),
    ]);

    $result = Payments::gateway('stripe')->find('cs_test_a1b2c3d4e5f6g7h8i9j0');

    expect($result->status)->toBe(PaymentStatus::Succeeded)
        ->and($result->redirectUrl)->toBeNull()
        ->and($result->isPaid())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && ! $request->hasHeader('Idempotency-Key'));
});

it('maps checkout session states conservatively', function (string $status, string $paymentStatus, PaymentStatus $expected) {
    Http::fake(['api.stripe.com/*' => Http::response([
        ...fixture('stripe/checkout-session-complete.json'),
        'status' => $status,
        'payment_status' => $paymentStatus,
    ])]);

    expect(Payments::gateway('stripe')->find('cs_test_1')->status)->toBe($expected);
})->with([
    'still on the checkout page' => ['open', 'unpaid', PaymentStatus::RequiresAction],
    'delayed payment method' => ['complete', 'unpaid', PaymentStatus::Pending],
    'free order' => ['complete', 'no_payment_required', PaymentStatus::Succeeded],
    'abandoned' => ['expired', 'unpaid', PaymentStatus::Canceled],
]);

it('refunds a checkout session through its payment intent', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/*' => Http::response(fixture('stripe/checkout-session-complete.json')),
        'api.stripe.com/v1/refunds' => Http::response(fixture('stripe/refund-succeeded.json')),
    ]);

    $refund = Payments::gateway('stripe')->refund('cs_test_a1b2c3d4e5f6g7h8i9j0', Money::of(1000, 'USD'), 'refund-order_1001-1');

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->succeeded())->toBeTrue()
        ->and($refund->refundId)->toBe('re_1Nispe2eZvKYlo2Cd31jOCgZ')
        ->and($refund->transactionId)->toBe('cs_test_a1b2c3d4e5f6g7h8i9j0')
        ->and($refund->amount?->equals(Money::of(1000, 'USD')))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.stripe.com/v1/refunds'
        && $request->hasHeader('Idempotency-Key', 'refund-order_1001-1')
        && formBody($request) === ['payment_intent' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa', 'amount' => '1000']);
});

it('refunds a payment intent directly and in full by default', function () {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(fixture('stripe/refund-succeeded.json'))]);

    Payments::gateway('stripe')->refund('pi_3MtwBwLkdIwHu7ix28a3tqPa');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => formBody($request) === ['payment_intent' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa']
        && str_starts_with($request->header('Idempotency-Key')[0] ?? '', 'refund-'));
});

it('will not refund a session that was never paid', function () {
    Http::fake(['api.stripe.com/*' => Http::response(fixture('stripe/checkout-session-open.json'))]);

    Payments::gateway('stripe')->refund('cs_test_a1b2c3d4e5f6g7h8i9j0');
})->throws(GatewayException::class, 'has no payment to refund');

it('surfaces stripe error messages', function () {
    Http::fake(['api.stripe.com/*' => Http::response(fixture('stripe/error-already-refunded.json'), 400)]);

    try {
        Payments::gateway('stripe')->refund('pi_3MtwBwLkdIwHu7ix28a3tqPa');
        $this->fail('Expected a GatewayException.');
    } catch (GatewayException $exception) {
        expect($exception->getMessage())->toContain('has already been refunded')
            ->and($exception->httpStatus)->toBe(400)
            ->and($exception->response['error']['code'] ?? null)->toBe('charge_already_refunded');
    }
});

it('treats stripe outages as an unknown outcome', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Server error']], 500)]);

    Payments::gateway('stripe')->createPayment(stripeRequest());
})->throws(GatewayUnavailable::class);

it('retries connection failures with the same idempotency key', function () {
    Http::fake(['api.stripe.com/*' => Http::sequence()
        ->pushFailedConnection()
        ->push(fixture('stripe/checkout-session-open.json')),
    ]);

    Payments::gateway('stripe')->createPayment(stripeRequest());

    Http::assertSentCount(2);
    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)->unique();
    expect($keys->all())->toBe(['checkout-session-order_1001']);
});

it('requires a secret key', function () {
    config()->set('payment-gateways.gateways.stripe.secret_key', null);

    Payments::gateway('stripe');
})->throws(InvalidConfiguration::class, 'secret_key');
