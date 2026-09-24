<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payment-gateways.http.retry_delay_ms', 0);
    config()->set('payment-gateways.gateways.paypal.client_id', 'client-id');
    config()->set('payment-gateways.gateways.paypal.client_secret', 'client-secret');
    config()->set('payment-gateways.gateways.paypal.webhook_id', '8PT597110X687430LKGECATA');
});

/**
 * @return array<string, string>
 */
function paypalSignatureHeaders(): array
{
    return [
        'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77',
        'HTTP_PAYPAL_TRANSMISSION_ID' => '103e3700-8b0c-11ef-9f4b-8d7dd3e7d4c5',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'MvvkEmBYeFMnFnpSVSVxS0oChjAzFZmLGgt2oPUtEUlc7WyOkPxsJHkOqrsPp0HJvwlX7CD5eDHYIHmn6pR1ssrjvgZx7SfUcCvTNvYO7AyX3hzvS3ANH1fjTHPdcUKNpVQjDfDsc5TgvOQmHoG3RSAIo3Uq3N6kP4bPKo1LeFVHXZxlhVwP3eV36UD1DUg2vmD8XcKfeoD3Awb1aXnLh7zzvTR/o7C8YW2mA/p5tjBkRY9zrnVNkZe8ev1gRvhlOd2LCAEuVHoCQrKxl3HgMY2rMKPHO6/8WEQYHLhPxATQmSFNOHV5hzeyqlCBnFIXJ7T47NFmGChfKDUzRTUkyA==',
        'HTTP_PAYPAL_TRANSMISSION_TIME' => '2024-09-24T10:05:02Z',
    ];
}

function paypalWebhook(string $body, ?array $headers = null): Request
{
    return Request::create('/webhooks/paypal', 'POST', server: $headers ?? paypalSignatureHeaders(), content: $body);
}

function paypalEventBody(): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/paypal/event-capture-completed.json');
}

it('verifies the event with paypal and translates it', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $event = Payments::gateway('paypal')->parseWebhook(paypalWebhook(paypalEventBody()));

    expect($event->id)->toBe('WH-2B342482FC0449155-12X09416XP387753C')
        ->and($event->gateway)->toBe('paypal')
        ->and($event->type)->toBe('PAYMENT.CAPTURE.COMPLETED')
        ->and($event->status)->toBe(PaymentStatus::Succeeded)
        ->and($event->transactionId)->toBe('5O190127TN364715T')
        ->and($event->reference)->toBe('order_1001')
        ->and($event->amount?->equals(Money::of(2500, 'USD')))->toBeTrue();
});

it('sends the transmission headers, webhook id and the untouched event', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    Payments::gateway('paypal')->parseWebhook(paypalWebhook(paypalEventBody()));

    Http::assertSent(function (ClientRequest $request) {
        if (! str_ends_with($request->url(), '/v1/notifications/verify-webhook-signature')) {
            return false;
        }

        $sent = json_decode($request->body(), true);

        return $sent['auth_algo'] === 'SHA256withRSA'
            && $sent['cert_url'] === 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77'
            && $sent['transmission_id'] === '103e3700-8b0c-11ef-9f4b-8d7dd3e7d4c5'
            && $sent['transmission_time'] === '2024-09-24T10:05:02Z'
            && str_starts_with($sent['transmission_sig'], 'MvvkEmBYeFMnFnpSVSVxS0oCh')
            && $sent['webhook_id'] === '8PT597110X687430LKGECATA'
            && str_ends_with($request->body(), ',"webhook_event":'.trim(paypalEventBody()).'}')
            && $request->hasHeader('Authorization', 'Bearer '.fixture('paypal/token.json')['access_token']);
    });
});

it('rejects events paypal does not vouch for', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);

    Payments::gateway('paypal')->parseWebhook(paypalWebhook(paypalEventBody()));
})->throws(InvalidSignature::class, 'PayPal did not confirm the signature.');

it('rejects requests without transmission headers before calling paypal', function () {
    Http::fake();
    $headers = paypalSignatureHeaders();
    unset($headers['HTTP_PAYPAL_TRANSMISSION_SIG']);

    expect(fn () => Payments::gateway('paypal')->parseWebhook(paypalWebhook(paypalEventBody(), $headers)))
        ->toThrow(InvalidSignature::class, 'PAYPAL-TRANSMISSION-SIG');

    Http::assertNothingSent();
});

it('rejects bodies that are not a json object', function () {
    Http::fake();

    Payments::gateway('paypal')->parseWebhook(paypalWebhook('[1,2,3]'));
})->throws(InvalidSignature::class, 'not a JSON object');

it('does not treat a verification outage as a valid signature', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        '*/v1/notifications/verify-webhook-signature' => Http::response([], 503),
    ]);

    Payments::gateway('paypal')->parseWebhook(paypalWebhook(paypalEventBody()));
})->throws(GatewayUnavailable::class);

it('maps paypal event types', function (string $type, ?PaymentStatus $expected) {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(fixture('paypal/token.json')),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $payload = fixture('paypal/event-capture-completed.json');
    $payload['event_type'] = $type;

    $event = Payments::gateway('paypal')->parseWebhook(paypalWebhook((string) json_encode($payload)));

    expect($event->status)->toBe($expected);
})->with([
    ['PAYMENT.CAPTURE.PENDING', PaymentStatus::Pending],
    ['PAYMENT.CAPTURE.DENIED', PaymentStatus::Failed],
    ['PAYMENT.CAPTURE.DECLINED', PaymentStatus::Failed],
    ['PAYMENT.CAPTURE.REFUNDED', PaymentStatus::Refunded],
    ['PAYMENT.CAPTURE.REVERSED', PaymentStatus::Refunded],
    ['CHECKOUT.ORDER.APPROVED', PaymentStatus::Authorized],
    ['BILLING.SUBSCRIPTION.CREATED', null],
]);

it('needs the webhook id to verify events', function () {
    config()->set('payment-gateways.gateways.paypal.webhook_id', null);

    Payments::gateway('paypal')->parseWebhook(paypalWebhook(paypalEventBody()));
})->throws(InvalidConfiguration::class, 'webhook_id');
