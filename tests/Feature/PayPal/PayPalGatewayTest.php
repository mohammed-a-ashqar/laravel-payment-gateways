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
use Alashqar\PaymentGateways\Gateways\PayPal\PayPalGateway;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PAYPAL_SANDBOX = 'https://api-m.sandbox.paypal.com';

beforeEach(function () {
    config()->set('payment-gateways.http.retry_delay_ms', 0);
    config()->set('payment-gateways.gateways.paypal.client_id', 'client-id');
    config()->set('payment-gateways.gateways.paypal.client_secret', 'client-secret');
});

function paypalRequest(): PaymentRequest
{
    return new PaymentRequest(
        amount: Money::of(2500, 'USD'),
        reference: 'order_1001',
        description: 'Order #1001',
        customer: new Customer(email: 'buyer@example.com'),
        returnUrl: 'https://shop.test/paypal/return',
        cancelUrl: 'https://shop.test/cart',
    );
}

it('creates an order and returns the payer approval link', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders' => Http::response(fixture('paypal/order-created.json'), 201),
    ]);

    $result = Payments::gateway('paypal')->createPayment(paypalRequest());

    expect($result->status)->toBe(PaymentStatus::RequiresAction)
        ->and($result->transactionId)->toBe('5O190127TN364715T')
        ->and($result->redirectUrl)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T')
        ->and($result->amount?->equals(Money::of(2500, 'USD')))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === PAYPAL_SANDBOX.'/v1/oauth2/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'))
        && $request->body() === 'grant_type=client_credentials');

    Http::assertSent(fn (Request $request) => $request->url() === PAYPAL_SANDBOX.'/v2/checkout/orders'
        && $request->hasHeader('Authorization', 'Bearer '.fixture('paypal/token.json')['access_token'])
        && $request->hasHeader('PayPal-Request-Id', 'order-order_1001')
        && $request->hasHeader('Prefer', 'return=representation')
        && $request->data() === [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'order_1001',
                'custom_id' => 'order_1001',
                'invoice_id' => 'order_1001',
                'description' => 'Order #1001',
                'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
            ]],
            'payment_source' => ['paypal' => [
                'email_address' => 'buyer@example.com',
                'experience_context' => [
                    'return_url' => 'https://shop.test/paypal/return',
                    'cancel_url' => 'https://shop.test/cart',
                    'user_action' => 'PAY_NOW',
                ],
            ]],
        ]);
});

it('caches the oauth token until shortly before it expires', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::response(fixture('paypal/order-captured.json')),
    ]);

    $paypal = Payments::gateway('paypal');
    $paypal->find('5O190127TN364715T');
    $paypal->find('5O190127TN364715T');

    $this->travel(31668 - 61)->seconds();
    $paypal->find('5O190127TN364715T');

    $tokenCalls = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token'));
    expect($tokenCalls)->toHaveCount(1);

    $this->travel(2)->seconds();
    $paypal->find('5O190127TN364715T');

    $tokenCalls = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token'));
    expect($tokenCalls)->toHaveCount(2);
});

it('refreshes a revoked token once and retries the call', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::sequence()
            ->push(['name' => 'AUTHENTICATION_FAILURE', 'message' => 'Authentication failed due to invalid authentication credentials or a missing Authorization header.'], 401)
            ->push(fixture('paypal/order-captured.json')),
    ]);

    expect(Payments::gateway('paypal')->find('5O190127TN364715T')->status)->toBe(PaymentStatus::Succeeded);

    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token')))->toHaveCount(2);
});

it('reports bad credentials clearly', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401),
    ]);

    Payments::gateway('paypal')->find('5O190127TN364715T');
})->throws(GatewayException::class, 'Client Authentication failed');

it('talks to the live api in live mode', function () {
    config()->set('payment-gateways.gateways.paypal.mode', 'live');
    Http::fake([
        'api-m.paypal.com/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        'api-m.paypal.com/v2/checkout/orders/*' => Http::response(fixture('paypal/order-captured.json')),
    ]);

    Payments::gateway('paypal')->find('5O190127TN364715T');

    Http::assertSent(fn (Request $request) => $request->url() === PayPalGateway::LIVE_URL.'/v2/checkout/orders/5O190127TN364715T');
});

it('captures an approved order', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/5O190127TN364715T/capture' => Http::response(fixture('paypal/order-captured.json'), 201),
    ]);

    $result = Payments::gateway('paypal')->capture('5O190127TN364715T');

    expect($result->status)->toBe(PaymentStatus::Succeeded)
        ->and($result->transactionId)->toBe('5O190127TN364715T')
        ->and($result->amount?->equals(Money::of(2500, 'USD')))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/capture')
        && $request->body() === '{}'
        && $request->hasHeader('Content-Type', 'application/json')
        && str_starts_with($request->header('PayPal-Request-Id')[0] ?? '', 'capture-'));
});

it('returns a declined capture as a failed payment instead of throwing', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*/capture' => Http::response(fixture('paypal/error-instrument-declined.json'), 422),
    ]);

    $result = Payments::gateway('paypal')->capture('5O190127TN364715T');

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('INSTRUMENT_DECLINED')
        ->and($result->isPaid())->toBeFalse();
});

it('treats capturing twice as a lookup', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*/capture' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'ORDER_ALREADY_CAPTURED', 'description' => 'Order already captured.']],
        ], 422),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::response(fixture('paypal/order-captured.json')),
    ]);

    expect(Payments::gateway('paypal')->capture('5O190127TN364715T')->status)->toBe(PaymentStatus::Succeeded);
});

it('maps order and capture states', function (string $orderStatus, ?string $captureStatus, PaymentStatus $expected) {
    $order = fixture('paypal/order-captured.json');
    $order['status'] = $orderStatus;
    $order['purchase_units'][0]['payments']['captures'][0]['status'] = $captureStatus;

    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::response($order),
    ]);

    expect(Payments::gateway('paypal')->find('5O190127TN364715T')->status)->toBe($expected);
})->with([
    ['CREATED', null, PaymentStatus::RequiresAction],
    ['APPROVED', null, PaymentStatus::Authorized],
    ['VOIDED', null, PaymentStatus::Canceled],
    ['COMPLETED', 'PENDING', PaymentStatus::Pending],
    ['COMPLETED', 'DECLINED', PaymentStatus::Failed],
    ['COMPLETED', 'PARTIALLY_REFUNDED', PaymentStatus::PartiallyRefunded],
    ['COMPLETED', 'REFUNDED', PaymentStatus::Refunded],
]);

it('refunds part of the capture behind an order', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::response(fixture('paypal/order-captured.json')),
        PAYPAL_SANDBOX.'/v2/payments/captures/3C679366HH908993F/refund' => Http::response(fixture('paypal/refund-completed.json'), 201),
    ]);

    $refund = Payments::gateway('paypal')->refund('5O190127TN364715T', Money::of(1000, 'USD'), 'refund-order_1001-1');

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->refundId)->toBe('1JU08902781691411')
        ->and($refund->amount?->equals(Money::of(1000, 'USD')))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund')
        && $request->hasHeader('PayPal-Request-Id', 'refund-order_1001-1')
        && $request->data() === ['amount' => ['currency_code' => 'USD', 'value' => '10.00']]);
});

it('refunds in full with an empty body', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::response(fixture('paypal/order-captured.json')),
        PAYPAL_SANDBOX.'/v2/payments/captures/*/refund' => Http::response(fixture('paypal/refund-completed.json'), 201),
    ]);

    Payments::gateway('paypal')->refund('5O190127TN364715T');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund') && $request->body() === '{}');
});

it('will not refund an order that was never captured', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders/*' => Http::response(fixture('paypal/order-created.json')),
    ]);

    Payments::gateway('paypal')->refund('5O190127TN364715T');
})->throws(GatewayException::class, 'has no capture to refund');

it('sends whole units for currencies paypal treats as zero-decimal', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders' => Http::response(fixture('paypal/order-created.json'), 201),
    ]);

    Payments::gateway('paypal')->createPayment(new PaymentRequest(Money::of(150000, 'HUF'), 'order_huf'));

    Http::assertSent(fn (Request $request) => ($request->data()['purchase_units'][0]['amount'] ?? null) === ['currency_code' => 'HUF', 'value' => '1500']);

    expect(fn () => Payments::gateway('paypal')->createPayment(new PaymentRequest(Money::of(150050, 'HUF'), 'order_huf2')))
        ->toThrow(InvalidArgumentException::class, 'whole HUF');
});

it('rejects currencies paypal does not support before calling it', function () {
    Http::fake();

    expect(fn () => Payments::gateway('paypal')->createPayment(new PaymentRequest(Money::of(100, 'SOS'), 'order_1')))
        ->toThrow(UnsupportedCurrency::class);

    Http::assertNothingSent();
});

it('surfaces paypal validation errors', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders' => Http::response([
            'name' => 'INVALID_REQUEST',
            'message' => 'Request is not well-formed, syntactically incorrect, or violates schema.',
            'details' => [['field' => '/purchase_units/0/amount/value', 'issue' => 'DECIMAL_PRECISION', 'description' => 'If the currency supports decimals, only two decimal place precision is supported.']],
        ], 400),
    ]);

    Payments::gateway('paypal')->createPayment(paypalRequest());
})->throws(GatewayException::class, 'only two decimal place precision');

it('treats paypal outages as an unknown outcome', function () {
    Http::fake([
        PAYPAL_SANDBOX.'/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        PAYPAL_SANDBOX.'/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
    ]);

    Payments::gateway('paypal')->createPayment(paypalRequest());
})->throws(GatewayUnavailable::class);

it('validates the configured mode', function () {
    config()->set('payment-gateways.gateways.paypal.mode', 'production');

    Payments::gateway('paypal');
})->throws(InvalidConfiguration::class, 'expected one of: sandbox, live.');
