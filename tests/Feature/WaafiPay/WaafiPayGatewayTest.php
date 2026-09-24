<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Data\Customer;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Exceptions\UnsupportedCurrency;
use Alashqar\PaymentGateways\Exceptions\UnsupportedOperation;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Gateways\WaafiPay\WaafiPayGateway;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payment-gateways.gateways.waafipay.merchant_uid', 'M0910291');
    config()->set('payment-gateways.gateways.waafipay.api_user_id', '1000416');
    config()->set('payment-gateways.gateways.waafipay.api_key', 'API-675418888AHX');
    Carbon::setTestNow(Carbon::createFromTimestamp(1730797484));
});

afterEach(fn () => Carbon::setTestNow());

function waafiRequest(string $phone = '+252 61 1111111'): PaymentRequest
{
    return new PaymentRequest(
        amount: Money::of(1000, 'USD'),
        reference: 'order_1001',
        description: 'Order #1001',
        customer: new Customer(phone: $phone),
    );
}

function waafiPay(): WaafiPayGateway
{
    $gateway = Payments::gateway('waafipay');

    return $gateway instanceof WaafiPayGateway ? $gateway : throw new LogicException('Unexpected driver.');
}

it('sends an API_PURCHASE envelope to the sandbox by default', function () {
    Http::fake([WaafiPayGateway::SANDBOX_URL => Http::response(fixture('waafipay/purchase-approved.json'))]);

    $result = waafiPay()->createPayment(waafiRequest());

    expect($result->status)->toBe(PaymentStatus::Succeeded)
        ->and($result->transactionId)->toBe('1268666')
        ->and($result->isPaid())->toBeTrue()
        ->and($result->amount?->equals(Money::of(1000, 'USD')))->toBeTrue();

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://sandbox.waafipay.com/asm'
            && $request->isJson()
            && $body['schemaVersion'] === '1.0'
            && preg_match('/^[0-9a-f-]{36}$/', $body['requestId']) === 1
            && $body['timestamp'] === '1730797484'
            && $body['channelName'] === 'WEB'
            && $body['serviceName'] === 'API_PURCHASE'
            && $body['serviceParams'] === [
                'merchantUid' => 'M0910291',
                'apiUserId' => '1000416',
                'apiKey' => 'API-675418888AHX',
                'paymentMethod' => 'MWALLET_ACCOUNT',
                'payerInfo' => ['accountNo' => '252611111111'],
                'transactionInfo' => [
                    'referenceId' => 'order_1001',
                    'invoiceId' => 'order_1001',
                    'amount' => '10.00',
                    'currency' => 'USD',
                    'description' => 'Order #1001',
                ],
            ];
    });
});

it('uses the production endpoint in live mode', function () {
    config()->set('payment-gateways.gateways.waafipay.mode', 'live');
    Http::fake([WaafiPayGateway::LIVE_URL => Http::response(fixture('waafipay/purchase-approved.json'))]);

    waafiPay()->createPayment(waafiRequest());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.waafipay.net/asm');
});

it('treats any response code other than 2001 as a failed payment', function () {
    Http::fake(['*' => Http::response(fixture('waafipay/purchase-failed.json'))]);

    $result = waafiPay()->createPayment(waafiRequest());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->isPaid())->toBeFalse()
        ->and($result->transactionId)->toBeNull()
        ->and($result->failureReason)->toBe('Payment Failed (error occurred, please try again later) (responseCode 5206, errorCode E10205)');
});

it('never reports money as paid without an APPROVED state', function (mixed $state) {
    $response = fixture('waafipay/purchase-approved.json');
    $response['params']['state'] = $state;
    Http::fake(['*' => Http::response($response)]);

    expect(waafiPay()->createPayment(waafiRequest())->status)->toBe(PaymentStatus::Pending);
})->with(['PENDING', null, '']);

it('does not trust a numeric 2001 or other look-alikes', function (mixed $code) {
    $response = fixture('waafipay/purchase-approved.json');
    $response['responseCode'] = $code;
    Http::fake(['*' => Http::response($response)]);

    expect(waafiPay()->createPayment(waafiRequest())->status)->toBe(PaymentStatus::Failed);
})->with(['2002', '20010', 'RCS_SUCCESS', null]);

it('needs the payer wallet number', function (?string $phone) {
    Http::fake();

    expect(fn () => waafiPay()->createPayment(new PaymentRequest(
        Money::of(1000, 'USD'),
        'order_1',
        customer: $phone === null ? null : new Customer(phone: $phone),
    )))->toThrow(InvalidArgumentException::class, 'wallet number');

    Http::assertNothingSent();
})->with([null, '0611111111', 'not-a-number', '12']);

it('only accepts the configured currencies', function () {
    Http::fake();

    waafiPay()->createPayment(new PaymentRequest(Money::of(1000, 'EUR'), 'order_1', customer: new Customer(phone: '252611111111')));
})->throws(UnsupportedCurrency::class);

it('does not retry purchases after a connection error because they are not idempotent', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => waafiPay()->createPayment(waafiRequest()))->toThrow(GatewayUnavailable::class);

    Http::assertSentCount(1);
});

it('reports http errors from waafipay as gateway errors', function () {
    Http::fake(['*' => Http::response('<html>Bad Request</html>', 400)]);

    waafiPay()->createPayment(waafiRequest());
})->throws(GatewayException::class, 'HTTP 400');

it('pre-authorizes, then commits the hold', function () {
    Http::fake(['*' => Http::sequence()
        ->push(fixture('waafipay/purchase-approved.json'))
        ->push(fixture('waafipay/reversal-approved.json')),
    ]);

    $hold = waafiPay()->authorize(waafiRequest());
    $captured = waafiPay()->capture((string) $hold->transactionId);

    expect($hold->status)->toBe(PaymentStatus::Authorized)
        ->and($captured->status)->toBe(PaymentStatus::Succeeded);

    $services = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['serviceName'])->all();
    expect($services)->toBe(['API_PREAUTHORIZE', 'API_PREAUTHORIZE_COMMIT']);

    Http::assertSent(fn (Request $request) => $request->data()['serviceName'] === 'API_PREAUTHORIZE'
        && ! array_key_exists('invoiceId', $request->data()['serviceParams']['transactionInfo']));

    Http::assertSent(fn (Request $request) => $request->data()['serviceName'] === 'API_PREAUTHORIZE_COMMIT'
        && $request->data()['serviceParams']['transactionId'] === '1268666');
});

it('reports a rejected commit as a failed payment', function () {
    Http::fake(['*' => Http::response(fixture('waafipay/purchase-failed.json'))]);

    $result = waafiPay()->capture('1268666');

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->transactionId)->toBe('1268666');
});

it('cancels a hold', function () {
    Http::fake(['*' => Http::response(fixture('waafipay/reversal-approved.json'))]);

    expect(waafiPay()->void('1268666')->status)->toBe(PaymentStatus::Canceled);

    Http::assertSent(fn (Request $request) => $request->data()['serviceName'] === 'API_PREAUTHORIZE_CANCEL');
});

it('throws when a hold cannot be cancelled, since its state is unknown', function () {
    Http::fake(['*' => Http::response(fixture('waafipay/purchase-failed.json'))]);

    waafiPay()->void('1268666');
})->throws(GatewayException::class, 'refused to cancel');

it('reverses a purchase in full', function () {
    Http::fake(['*' => Http::response(fixture('waafipay/reversal-approved.json'))]);

    $refund = waafiPay()->refund('1268666');

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->refundId)->toBe('1268667')
        ->and($refund->transactionId)->toBe('1268666');

    Http::assertSent(fn (Request $request) => $request->data()['serviceName'] === 'API_REVERSAL'
        && $request->data()['serviceParams']['transactionId'] === '1268666');
});

it('reports a rejected reversal as a failed refund', function () {
    Http::fake(['*' => Http::response(fixture('waafipay/purchase-failed.json'))]);

    $refund = waafiPay()->refund('1268666');

    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->failureReason)->toContain('responseCode 5206');
});

it('is honest about what waafipay does not document', function (Closure $call, string $message) {
    Http::fake();

    expect(fn () => $call(waafiPay()))->toThrow(UnsupportedOperation::class, $message);

    Http::assertNothingSent();
})->with([
    'partial refunds' => [fn (WaafiPayGateway $gateway) => $gateway->refund('1268666', Money::of(500, 'USD')), 'partial refunds'],
    'lookups' => [fn (WaafiPayGateway $gateway) => $gateway->find('1268666'), 'transaction lookups'],
]);
