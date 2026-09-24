<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Gateways\WaafiPay\WaafiPaySignature;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

const WAAFIPAY_WEBHOOK_SECRET = 'waafi-webhook-secret';

beforeEach(function () {
    config()->set('payment-gateways.gateways.waafipay.merchant_uid', 'M0910291');
    config()->set('payment-gateways.gateways.waafipay.api_user_id', '1000416');
    config()->set('payment-gateways.gateways.waafipay.api_key', 'API-675418888AHX');
    config()->set('payment-gateways.gateways.waafipay.webhook_secret', WAAFIPAY_WEBHOOK_SECRET);
    Carbon::setTestNow(Carbon::createFromTimestamp(1755097445));
});

afterEach(fn () => Carbon::setTestNow());

function waafiWebhook(string $body, array $headers): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/webhooks/waafipay', 'POST', server: $server, content: $body);
}

/**
 * @return array<string, string>
 */
function waafiSignedHeaders(string $body, int $timestamp = 1755097445, string $eventId = 'evt_7f3a2c', string $secret = WAAFIPAY_WEBHOOK_SECRET): array
{
    return [
        'X-Webhook-Timestamp' => (string) $timestamp,
        'X-Webhook-Event-Id' => $eventId,
        'X-Webhook-Signature-Alg' => 'HMAC-SHA256',
        'X-Webhook-Signature' => WaafiPaySignature::sign($body, $secret, $timestamp, $eventId),
        'User-Agent' => 'WPNotifyService',
    ];
}

function waafiWebhookBody(): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/waafipay/webhook-authorization.json');
}

it('accepts a correctly signed authorization webhook', function () {
    $body = waafiWebhookBody();

    $event = Payments::gateway('waafipay')->parseWebhook(waafiWebhook($body, waafiSignedHeaders($body)));

    expect($event->id)->toBe('evt_7f3a2c')
        ->and($event->gateway)->toBe('waafipay')
        ->and($event->type)->toBe('authorization')
        ->and($event->status)->toBe(PaymentStatus::Succeeded)
        ->and($event->transactionId)->toBe('1303630')
        ->and($event->reference)->toBe('REF-2024-4567')
        ->and($event->amount?->equals(Money::of(10050, 'USD')))->toBeTrue();
});

it('binds the event id into the signature', function () {
    $body = waafiWebhookBody();
    $headers = waafiSignedHeaders($body);
    $headers['X-Webhook-Event-Id'] = 'evt_other';

    Payments::gateway('waafipay')->parseWebhook(waafiWebhook($body, $headers));
})->throws(InvalidSignature::class, 'does not match');

it('rejects tampered bodies and foreign secrets', function (Closure $tamper) {
    $body = waafiWebhookBody();
    [$sentBody, $headers] = $tamper($body, waafiSignedHeaders($body));

    Payments::gateway('waafipay')->parseWebhook(waafiWebhook($sentBody, $headers));
})->throws(InvalidSignature::class)->with([
    'tampered amount' => fn (string $body, array $headers) => [str_replace('100.50', '1.00', $body), $headers],
    'foreign secret' => fn (string $body, array $headers) => [$body, waafiSignedHeaders($body, secret: 'guess')],
    'missing signature' => fn (string $body, array $headers) => [$body, array_diff_key($headers, ['X-Webhook-Signature' => true])],
    'missing event id' => fn (string $body, array $headers) => [$body, array_diff_key($headers, ['X-Webhook-Event-Id' => true])],
    'non numeric timestamp' => fn (string $body, array $headers) => [$body, ['X-Webhook-Timestamp' => 'now'] + $headers],
]);

it('rejects stale webhooks', function () {
    $body = waafiWebhookBody();

    Payments::gateway('waafipay')->parseWebhook(waafiWebhook($body, waafiSignedHeaders($body, timestamp: 1755097445 - 301)));
})->throws(InvalidSignature::class, 'outside the tolerance window');

it('maps waafipay payment states', function (string $event, string $state, ?PaymentStatus $expected) {
    $payload = fixture('waafipay/webhook-authorization.json');
    $payload['event'] = $event;
    $payload['payment']['status'] = $state;
    $body = (string) json_encode($payload);

    $parsed = Payments::gateway('waafipay')->parseWebhook(waafiWebhook($body, waafiSignedHeaders($body)));

    expect($parsed->status)->toBe($expected);
})->with([
    ['authorization', 'DECLINED', PaymentStatus::Failed],
    ['authorization', 'FAILED', PaymentStatus::Failed],
    ['authorization', 'EXPIRED', PaymentStatus::Canceled],
    ['authorization', 'TIMEOUT', PaymentStatus::Canceled],
    ['refund', 'APPROVED', PaymentStatus::Refunded],
    ['refund', 'FAILED', null],
]);

it('needs a webhook secret', function () {
    config()->set('payment-gateways.gateways.waafipay.webhook_secret', null);

    Payments::gateway('waafipay')->parseWebhook(waafiWebhook('{}', []));
})->throws(InvalidConfiguration::class, 'webhook_secret');
