<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Events\PaymentFailed;
use Alashqar\PaymentGateways\Events\PaymentRefunded;
use Alashqar\PaymentGateways\Events\PaymentSucceeded;
use Alashqar\PaymentGateways\Events\WebhookReceived;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Facades\Payments;
use Alashqar\PaymentGateways\Gateways\Stripe\StripeSignature;
use Alashqar\PaymentGateways\PaymentGatewaysServiceProvider;
use Alashqar\PaymentGateways\Webhooks\ReplayGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const PACKAGE_EVENTS = [WebhookReceived::class, PaymentSucceeded::class, PaymentFailed::class, PaymentRefunded::class];

function fakeWebhook(array $payload): string
{
    return (string) json_encode($payload + ['transaction_id' => 'fake_1', 'reference' => 'order_1']);
}

it('registers a named webhook route', function () {
    expect(route('payment-gateways.webhook', ['gateway' => 'stripe'], false))
        ->toBe('/payment-gateways/webhooks/stripe');
});

it('dispatches the matching event for each payment outcome', function (string $status, string $expected) {
    Payments::fake();
    Event::fake(PACKAGE_EVENTS);

    $this->call('POST', '/payment-gateways/webhooks/stripe', content: fakeWebhook(['id' => 'evt_'.$status, 'status' => $status]))
        ->assertOk()
        ->assertJson(['message' => 'Processed.']);

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->webhook->id === 'evt_'.$status);
    Event::assertDispatched($expected, fn (object $e) => $e->webhook->reference === 'order_1');
})->with([
    ['succeeded', PaymentSucceeded::class],
    ['failed', PaymentFailed::class],
    ['canceled', PaymentFailed::class],
    ['refunded', PaymentRefunded::class],
    ['partially_refunded', PaymentRefunded::class],
]);

it('only dispatches WebhookReceived for events without a payment outcome', function () {
    Payments::fake();
    Event::fake(PACKAGE_EVENTS);

    $this->call('POST', '/payment-gateways/webhooks/stripe', content: fakeWebhook(['id' => 'evt_1', 'status' => 'pending']))->assertOk();

    Event::assertDispatched(WebhookReceived::class);
    Event::assertNotDispatched(PaymentSucceeded::class);
    Event::assertNotDispatched(PaymentFailed::class);
    Event::assertNotDispatched(PaymentRefunded::class);
});

it('processes a redelivered event only once', function () {
    Payments::fake();
    Event::fake(PACKAGE_EVENTS);
    $body = fakeWebhook(['id' => 'evt_dup', 'status' => 'succeeded']);

    $this->call('POST', '/payment-gateways/webhooks/stripe', content: $body)->assertOk();
    $this->call('POST', '/payment-gateways/webhooks/stripe', content: $body)
        ->assertOk()
        ->assertJson(['message' => 'Already processed.']);

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('keeps replay protection per gateway', function () {
    $guard = app(ReplayGuard::class);

    expect($guard->claim(new WebhookEvent('evt_same', 'stripe', 'x', null)))->toBeTrue()
        ->and($guard->claim(new WebhookEvent('evt_same', 'paypal', 'x', null)))->toBeTrue()
        ->and($guard->claim(new WebhookEvent('evt_same', 'stripe', 'x', null)))->toBeFalse();
});

it('forgets processed events after the replay window', function () {
    $guard = app(ReplayGuard::class);
    $event = new WebhookEvent('evt_old', 'stripe', 'x', null);

    $guard->claim($event);
    $this->travel(config('payment-gateways.webhooks.replay_ttl') + 1)->seconds();

    expect($guard->claim($event))->toBeTrue();
});

it('lets the gateway retry when a listener fails', function () {
    Payments::fake();
    $body = fakeWebhook(['id' => 'evt_retry', 'status' => 'succeeded']);
    $attempts = 0;

    Event::listen(PaymentSucceeded::class, function () use (&$attempts) {
        if (++$attempts === 1) {
            throw new RuntimeException('Database is down');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->call('POST', '/payment-gateways/webhooks/stripe', content: $body))
        ->toThrow(RuntimeException::class, 'Database is down');

    $this->call('POST', '/payment-gateways/webhooks/stripe', content: $body)
        ->assertOk()
        ->assertJson(['message' => 'Processed.']);

    expect($attempts)->toBe(2);
});

it('verifies real signatures end to end', function () {
    config()->set('payment-gateways.gateways.stripe.secret_key', 'sk_test_123');
    config()->set('payment-gateways.gateways.stripe.webhook_secret', 'whsec_e2e');
    Carbon::setTestNow(Carbon::createFromTimestamp(1727170500));
    Event::fake(PACKAGE_EVENTS);

    $body = (string) file_get_contents(__DIR__.'/../Fixtures/stripe/event-checkout-session-completed.json');

    $this->call('POST', '/payment-gateways/webhooks/stripe', server: [
        'HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($body, 'whsec_e2e', 1727170500),
    ], content: $body)->assertOk();

    $this->call('POST', '/payment-gateways/webhooks/stripe', server: [
        'HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($body, 'whsec_forged', 1727170500),
    ], content: $body)->assertStatus(400)->assertJson(['message' => 'Invalid signature.']);

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->webhook->status === PaymentStatus::Succeeded
        && $e->webhook->transactionId === 'cs_test_a1b2c3d4e5f6g7h8i9j0');

    Carbon::setTestNow();
});

it('answers 404 for gateways that do not exist', function () {
    $this->call('POST', '/payment-gateways/webhooks/unknown', content: '{}')->assertNotFound();
});

it('answers 503 when the gateway cannot verify the event right now', function () {
    Payments::fake()->willThrow(new GatewayUnavailable('PayPal is down', 'paypal'));
    Event::fake(PACKAGE_EVENTS);

    $this->call('POST', '/payment-gateways/webhooks/paypal', content: '{}')->assertStatus(503);

    Event::assertNothingDispatched();
});

it('does not dispatch anything for unsigned requests', function () {
    config()->set('payment-gateways.gateways.stripe.secret_key', 'sk_test_123');
    config()->set('payment-gateways.gateways.stripe.webhook_secret', 'whsec_e2e');
    Event::fake(PACKAGE_EVENTS);

    $this->call('POST', '/payment-gateways/webhooks/stripe', content: '{"id":"evt_1","type":"checkout.session.completed"}')
        ->assertStatus(400);

    Event::assertNothingDispatched();
});

it('honours a custom path and middleware', function () {
    config()->set('payment-gateways.webhooks.path', '/hooks/payments/');
    config()->set('payment-gateways.webhooks.middleware', ['throttle:60,1']);
    (new PaymentGatewaysServiceProvider(app()))->boot();

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'hooks/payments/{gateway}');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:60,1');
});

it('can be switched off', function () {
    config()->set('payment-gateways.webhooks.enabled', false);
    config()->set('payment-gateways.webhooks.path', 'disabled-hooks');
    (new PaymentGatewaysServiceProvider(app()))->boot();

    Http::fake();

    $this->call('POST', '/disabled-hooks/stripe', content: '{}')->assertNotFound();
});
