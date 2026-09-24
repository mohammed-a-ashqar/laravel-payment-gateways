<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Gateways\Stripe\StripeSignature;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

const STRIPE_WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config()->set('payment-gateways.gateways.stripe.secret_key', 'sk_test_123');
    config()->set('payment-gateways.gateways.stripe.webhook_secret', STRIPE_WEBHOOK_SECRET);
    Carbon::setTestNow(Carbon::createFromTimestamp(1727170500));
});

afterEach(fn () => Carbon::setTestNow());

function stripeWebhook(string $body, ?string $signature): Request
{
    $server = $signature === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $signature];

    return Request::create('/webhooks/stripe', 'POST', server: $server, content: $body);
}

function stripeEventBody(string $fixture): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/stripe/'.$fixture);
}

it('accepts a correctly signed checkout event', function () {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $header = StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, 1727170500);

    $event = Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header));

    expect($event->id)->toBe('evt_1PqRsTLkdIwHu7ixAbCdEfGh')
        ->and($event->gateway)->toBe('stripe')
        ->and($event->type)->toBe('checkout.session.completed')
        ->and($event->status)->toBe(PaymentStatus::Succeeded)
        ->and($event->transactionId)->toBe('cs_test_a1b2c3d4e5f6g7h8i9j0')
        ->and($event->reference)->toBe('order_1001')
        ->and($event->amount?->equals(Money::of(2500, 'USD')))->toBeTrue();
});

it('verifies against the raw body byte for byte', function () {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $header = StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, 1727170500);
    $reencoded = (string) json_encode(json_decode($body, true));

    Payments::gateway('stripe')->parseWebhook(stripeWebhook($reencoded, $header));
})->throws(InvalidSignature::class, 'no signature matches the payload');

it('rejects signatures made with another secret', function () {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $header = StripeSignature::sign($body, 'whsec_attacker', 1727170500);

    Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header));
})->throws(InvalidSignature::class, 'no signature matches the payload');

it('rejects replayed events outside the tolerance window', function (int $signedAt) {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $header = StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, $signedAt);

    Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header));
})->throws(InvalidSignature::class, 'outside the tolerance window')->with([
    'ten minutes old' => 1727170500 - 600,
    'from the future' => 1727170500 + 600,
]);

it('accepts events signed just inside the tolerance window', function () {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $header = StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, 1727170500 - 300);

    expect(Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header))->id)
        ->toBe('evt_1PqRsTLkdIwHu7ixAbCdEfGh');
});

it('accepts any matching v1 signature while a secret is being rolled', function () {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $valid = hash_hmac('sha256', '1727170500.'.$body, STRIPE_WEBHOOK_SECRET);
    $header = "t=1727170500,v1=deadbeef,v1={$valid},v0=ignored";

    expect(Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header))->status)
        ->toBe(PaymentStatus::Succeeded);
});

it('ignores downgraded signature schemes', function () {
    $body = stripeEventBody('event-checkout-session-completed.json');
    $v0 = hash_hmac('sha256', '1727170500.'.$body, STRIPE_WEBHOOK_SECRET);

    Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, "t=1727170500,v0={$v0}"));
})->throws(InvalidSignature::class, 'no v1 signature');

it('rejects malformed or missing headers', function (?string $header, string $reason) {
    $body = stripeEventBody('event-checkout-session-completed.json');

    expect(fn () => Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header)))
        ->toThrow(InvalidSignature::class, $reason);
})->with([
    'missing' => [null, 'header is missing'],
    'no timestamp' => ['v1=abc', 'no valid timestamp'],
    'garbage timestamp' => ['t=yesterday,v1=abc', 'no valid timestamp'],
]);

it('translates refunds with the payment intent and reference', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1727180000));
    $body = stripeEventBody('event-charge-refunded.json');
    $header = StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, 1727180000);

    $event = Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, $header));

    expect($event->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($event->transactionId)->toBe('pi_3MtwBwLkdIwHu7ix28a3tqPa')
        ->and($event->reference)->toBe('order_1001')
        ->and($event->amount?->equals(Money::of(1000, 'USD')))->toBeTrue();
});

it('maps checkout lifecycle events', function (string $type, ?PaymentStatus $expected) {
    $payload = json_decode(stripeEventBody('event-checkout-session-completed.json'), true);
    $payload['type'] = $type;
    $body = (string) json_encode($payload);

    $event = Payments::gateway('stripe')->parseWebhook(
        stripeWebhook($body, StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, 1727170500))
    );

    expect($event->status)->toBe($expected);
})->with([
    ['checkout.session.async_payment_succeeded', PaymentStatus::Succeeded],
    ['checkout.session.async_payment_failed', PaymentStatus::Failed],
    ['checkout.session.expired', PaymentStatus::Canceled],
    ['customer.created', null],
]);

it('rejects a signed payload that is not an event', function () {
    $body = '{"hello":"world"}';

    Payments::gateway('stripe')->parseWebhook(stripeWebhook($body, StripeSignature::sign($body, STRIPE_WEBHOOK_SECRET, 1727170500)));
})->throws(GatewayException::class, 'not an Event object');

it('refuses to accept webhooks without a configured secret', function () {
    config()->set('payment-gateways.gateways.stripe.webhook_secret', null);

    Payments::gateway('stripe')->parseWebhook(stripeWebhook('{}', 't=1,v1=a'));
})->throws(InvalidConfiguration::class, 'webhook_secret');

it('does not allow a zero tolerance', function () {
    new StripeSignature('whsec', 0);
})->throws(InvalidArgumentException::class);
