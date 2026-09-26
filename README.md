# Laravel Payment Gateways

[![CI](https://github.com/mohammed-a-ashqar/laravel-payment-gateways/actions/workflows/ci.yml/badge.svg)](https://github.com/mohammed-a-ashqar/laravel-payment-gateways/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20)

A small, driver-based payment layer for Laravel with **Stripe**, **PayPal** and **WaafiPay** drivers.
Every driver talks to the gateway's REST API through Laravel's HTTP client — no vendor SDKs — and
exposes the same API: create a payment, look it up, refund it, and verify its webhooks.

```php
$result = Payments::gateway('stripe')->createPayment(new PaymentRequest(
    amount: Money::of(2500, 'USD'),          // $25.00, always in minor units
    reference: 'order_1001',                 // your id, also the idempotency key
    returnUrl: route('checkout.return'),
    cancelUrl: route('cart'),
));

return redirect()->away($result->redirectUrl);
```

## Why

I have integrated Stripe, PayPal and WaafiPay in client projects. Each time the same problems came
back: floats for money, retries that could charge twice, webhook handlers that trusted unsigned
requests, "paid" flags set from an unrecognised response code. This package is the version of that
code I would want to inherit:

- **Money is never a float.** `Money` stores integers in the currency's minor unit and refuses
  mixed-currency arithmetic.
- **Unknown is not the same as failed, and failed is not the same as paid.** Declines come back as
  a `PaymentResult` with a failed status; exceptions are reserved for invalid requests and for
  outcomes that are genuinely unknown (`GatewayUnavailable`).
- **Retries are safe.** Only connection errors are retried, and every money-moving request that the
  gateway allows carries an idempotency key.
- **Webhooks are verified, de-duplicated and turned into Laravel events.**
- **Tests don't need the network.** `Payments::fake()` works like `Mail::fake()`.

## Requirements

- PHP 8.2+
- Laravel 11 or 12 (Laravel 11 is past its security-support window; it is still tested in CI)

## Installation

```bash
composer require mohammed-a-ashqar/laravel-payment-gateways
php artisan vendor:publish --tag=payment-gateways-config
```

The service provider and the `Payments` facade are auto-discovered.

## Configuration

`config/payment-gateways.php` reads everything from the environment:

```dotenv
PAYMENT_GATEWAY=stripe

STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_MODE=sandbox            # or live
PAYPAL_WEBHOOK_ID=...

WAAFIPAY_MERCHANT_UID=...
WAAFIPAY_API_USER_ID=...
WAAFIPAY_API_KEY=...
WAAFIPAY_MODE=sandbox          # or live
WAAFIPAY_WEBHOOK_SECRET=...    # only for Hosted Payment Page webhooks
```

HTTP behaviour is configured once under `http` and can be overridden per gateway:

```php
'http' => [
    'timeout' => 30,          // seconds
    'connect_timeout' => 10,
    'retries' => 2,           // connection errors only
    'retry_delay_ms' => 250,
],
```

Each gateway also accepts a `currencies` allow-list. It is checked before any request is sent and
throws `UnsupportedCurrency`. PayPal defaults to the 24 currencies PayPal supports, WaafiPay to
`USD`, and Stripe to `null` (let Stripe decide).

Missing credentials fail fast with an `InvalidConfiguration` exception that names the missing key.

## Usage

All gateways implement `Alashqar\PaymentGateways\Contracts\Gateway`:

```php
interface Gateway
{
    public function name(): string;
    public function createPayment(PaymentRequest $request): PaymentResult;
    public function find(string $transactionId): PaymentResult;
    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult;
    public function parseWebhook(Request $request): WebhookEvent;
}
```

A `PaymentResult` carries a `PaymentStatus`, the gateway's transaction id, the amount, a redirect
URL when the payer has to act, a failure reason, and the raw response for your logs.

```php
use Alashqar\PaymentGateways\Enums\PaymentStatus;

match ($result->status) {
    PaymentStatus::RequiresAction => redirect()->away($result->redirectUrl),
    PaymentStatus::Succeeded => $order->markPaid($result->transactionId),
    PaymentStatus::Failed => back()->withErrors($result->failureReason),
    default => $order->markPending($result->transactionId),
};
```

`PaymentStatus` has a small transition guard, so a late "failed" webhook cannot overwrite a
payment you already recorded as succeeded:

```php
$order->status = $order->status->transitionTo($event->status); // throws InvalidStatusTransition
```

```
pending ──▶ requires_action ──▶ authorized ──▶ succeeded ──▶ partially_refunded ──▶ refunded
   │              │                 │              └──────────────────────────────────▲
   └──────────────┴─────────────────┴──▶ failed | canceled   (final)
```

### Stripe

Payments use hosted [Checkout Sessions](https://docs.stripe.com/api/checkout/sessions/create).
The transaction id is the session id (`cs_...`).

```php
$stripe = Payments::gateway('stripe');

$result = $stripe->createPayment(new PaymentRequest(
    amount: Money::of(2500, 'USD'),
    reference: 'order_1001',
    description: 'Order #1001',
    customer: new Customer(email: 'buyer@example.com'),
    returnUrl: route('checkout.return').'?session_id={CHECKOUT_SESSION_ID}',
    cancelUrl: route('cart'),
    metadata: ['cart_id' => '42'],
));

$stripe->find('cs_test_...');                                  // status of the session
$stripe->refund('cs_test_...');                                // full refund
$stripe->refund('cs_test_...', Money::of(1000, 'USD'), 'refund-order_1001-1'); // partial
```

- Requests are form-encoded and authenticated with your secret key.
- `createPayment` sends `Idempotency-Key: checkout-session-{reference}`; refunds use the key you pass
  or a fresh UUID. Connection retries reuse the same key.
- Refunds accept a Checkout Session id (its PaymentIntent is looked up for you) or a `pi_...` id.
- Set `api_version` in the config to pin a `Stripe-Version`.

### PayPal

Payments use the [Orders v2 API](https://developer.paypal.com/docs/api/orders/v2/) with
`intent: CAPTURE`. The transaction id is the PayPal order id.

```php
$paypal = Payments::gateway('paypal');

$result = $paypal->createPayment(new PaymentRequest(
    amount: Money::of(2500, 'USD'),
    reference: 'order_1001',
    returnUrl: route('paypal.return'),
    cancelUrl: route('cart'),
));

return redirect()->away($result->redirectUrl); // payer approves on PayPal

// In your return route, PayPal appends ?token={order id}:
$captured = $paypal->capture($request->query('token'));

$paypal->refund($orderId, Money::of(1000, 'USD'));
```

- OAuth2 client-credentials tokens are cached (default cache store, or `cache_store`) until 60 seconds
  before they expire, and refreshed once automatically if PayPal answers 401.
- `PayPal-Request-Id` is `order-{reference}` for order creation. The reference is also sent as
  `custom_id` and `invoice_id`, so PayPal's duplicate-invoice check protects you too.
- A declined capture (HTTP 422, e.g. `INSTRUMENT_DECLINED`) is returned as a failed result, and
  capturing an order twice returns its current state instead of throwing.
- Refunds find the order's capture and refund it; omit the amount for a full refund.
- HUF and TWD are sent as whole units, as PayPal requires.

### WaafiPay

Mobile-wallet payments through WaafiPay's API channel (`POST /asm`). The payer receives a prompt on
their phone and approves it with their PIN.

```php
$waafi = Payments::gateway('waafipay');

$result = $waafi->createPayment(new PaymentRequest(
    amount: Money::fromDecimal('10.00', 'USD'),
    reference: 'order_1001',
    description: 'Order #1001',
    customer: new Customer(phone: '252611111111'), // payer wallet, international format
));

$result->isPaid(); // true only for responseCode "2001" with state APPROVED

// Place a hold, then collect or release it:
$hold = $waafi->authorize($request);   // API_PREAUTHORIZE
$waafi->capture($hold->transactionId); // API_PREAUTHORIZE_COMMIT
$waafi->void($hold->transactionId);    // API_PREAUTHORIZE_CANCEL

$waafi->refund($result->transactionId); // API_REVERSAL, full amount only
```

- Every request is the documented envelope: `schemaVersion`, `requestId` (UUID), `timestamp`,
  `channelName: WEB`, `serviceName` and `serviceParams` with your credentials.
- Only `responseCode === "2001"` counts as success. A `2001` whose `state` is not `APPROVED` is
  reported as pending; every other code is a failed result with WaafiPay's message and codes.
- **Retries are off** (`http.retries = 0`) for WaafiPay because it documents no idempotency key; a
  retried purchase could charge the payer twice. The default timeout is 90 seconds because the
  payer has to confirm on their handset.

### Checking capabilities

Some features only exist on some gateways, so they live in separate contracts:

```php
use Alashqar\PaymentGateways\Contracts\AuthorizesPayments;
use Alashqar\PaymentGateways\Contracts\CapturesPayments;

if ($gateway instanceof CapturesPayments) {
    $gateway->capture($transactionId);
}
```

## Supported features

| | Stripe | PayPal | WaafiPay |
|---|---|---|---|
| Create payment | Checkout Session (redirect) | Order v2 (redirect) | `API_PURCHASE` (phone prompt) |
| Look up a payment (`find`) | Yes | Yes | No (no documented inquiry for the API channel) |
| Capture after approval | not needed | Yes, `capture()` | Yes, `capture()` of a pre-authorization |
| Authorize / void a hold | No | No | Yes, `authorize()` / `void()` |
| Full refund | Yes | Yes | Yes, `API_REVERSAL` (WaafiPay documents it for unsettled purchases, within 24h) |
| Partial refund | Yes | Yes | No |
| Idempotency | `Idempotency-Key` | `PayPal-Request-Id` | none documented, so no automatic retries |
| Webhook verification | local HMAC-SHA256 (`v1`) + timestamp tolerance | PayPal's verify-webhook-signature API | local HMAC-SHA256 + timestamp tolerance |
| Webhook events mapped | `checkout.session.*`, `charge.refunded` | `PAYMENT.CAPTURE.*`, `CHECKOUT.ORDER.APPROVED` | `authorization`, `refund` |

Not supported (yet): saved cards and customers, subscriptions, Stripe PaymentIntents/Elements
flows, PayPal `AUTHORIZE` intent, disputes, payouts, and WaafiPay's Hosted Payment Page checkout.

## Webhooks

The package registers one route (toggle it with `webhooks.enabled`):

```
POST /payment-gateways/webhooks/{gateway}      name: payment-gateways.webhook
```

Point each gateway's dashboard at it, e.g. `https://shop.test/payment-gateways/webhooks/stripe`.
The route is not in the `web` group, so no CSRF token is required. Add middleware such as
`throttle:120,1` with `webhooks.middleware`, and change the prefix with `webhooks.path`.

For every request the controller:

1. resolves the driver (`404` for an unknown gateway);
2. verifies the request with the driver (`400` on a bad signature, `503` if verification itself
   could not complete, so the gateway retries later);
3. claims the event id in the cache for `replay_ttl` seconds (default 7 days) — a redelivered event
   gets `200 Already processed.` and dispatches nothing;
4. dispatches `WebhookReceived` and then one of:

| Event | Dispatched when the status is |
|---|---|
| `PaymentSucceeded` | `succeeded` |
| `PaymentFailed` | `failed` or `canceled` |
| `PaymentRefunded` | `refunded` or `partially_refunded` |

If a listener throws, the claim is released so the gateway's next delivery is processed.

```php
use Alashqar\PaymentGateways\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $webhook = $event->webhook; // WebhookEvent: id, gateway, type, status, transactionId, reference, amount, payload

    Order::where('reference', $webhook->reference)->first()?->markPaid();
});
```

Correlate on `reference` where you can: it is your own id and is echoed back by all three gateways.
Stripe refund events (`charge.refunded`) carry the PaymentIntent id as `transactionId`, because a
Charge does not reference its Checkout Session. PayPal's refund events do not say whether the
capture is now fully refunded, so they are reported as `refunded`; call `find()` for the exact state.

Replay protection relies on `Cache::add()`, which is atomic on Redis, Memcached and the database
store. Use one of those in production.

## Testing your application

```php
use Alashqar\PaymentGateways\Facades\Payments;

it('charges the customer at checkout', function () {
    $payments = Payments::fake();

    $this->post('/checkout', ['plan' => 'pro'])->assertRedirect();

    $payments->assertCharged(Money::of(2900, 'USD'));
    $payments->assertCharged(fn (PaymentRequest $request) => $request->reference === 'order_1');
    $payments->assertChargedTimes(1);
    $payments->assertNothingRefunded();
});

it('shows an error when the card is declined', function () {
    Payments::fake()->willFail('card_declined');
    // ...
});
```

`Payments::fake()` swaps the manager, so every gateway name — and the webhook route — uses the same
in-memory `FakeGateway`. It can also `willRequireAction()`, `willThrow($exception)`, authorize,
capture, void and refund (through the real status transition guard). Its `parseWebhook()` accepts
unsigned JSON such as `{"id": "evt_1", "status": "succeeded", "reference": "order_1"}`, which makes
it easy to test your listeners end to end. It must never be used outside tests.

## Adding your own gateway

Implement the `Gateway` contract and register it:

```php
use Alashqar\PaymentGateways\Facades\Payments;

// config/payment-gateways.php → 'gateways' => ['acme' => ['api_key' => env('ACME_KEY')]]

Payments::extend('acme', function ($app, array $config) {
    return new AcmeGateway(
        client: Payments::client('acme'), // same timeouts and retry rules as the built-in drivers
        apiKey: $config['api_key'],
    );
});

Payments::gateway('acme')->createPayment($request);
```

The webhook route works for custom drivers automatically: `POST /payment-gateways/webhooks/acme`.

## Architecture

```
                   Payments facade / PaymentManager (Illuminate\Support\Manager)
                                   │  gateway('stripe'|'paypal'|'waafipay'|custom)
          ┌────────────────────────┼─────────────────────────┐
          ▼                        ▼                         ▼
   StripeGateway            PayPalGateway             WaafiPayGateway          FakeGateway
   StripeSignature          PayPalTokenProvider ─▶ Cache   WaafiPaySignature     (tests)
   StripeWebhookTranslator  PayPalWebhookTranslator        WaafiPayWebhookTranslator
          │                        │                         │
          └────────────┬───────────┴─────────────────────────┘
                       ▼
                GatewayClient ── Laravel HTTP client (timeouts, connection-only retries,
                       │                              5xx/connection errors → GatewayUnavailable)
                       ▼
                Payload (typed, null-safe reads of gateway JSON)

 Incoming:  POST /payment-gateways/webhooks/{gateway}
            WebhookController ─▶ driver->parseWebhook() ─▶ ReplayGuard (cache) ─▶ Laravel events
```

Shared value objects live in `src/Data` (`PaymentRequest`, `PaymentResult`, `RefundResult`,
`WebhookEvent`, `Customer`) and `src/Money.php`. Exceptions extend `GatewayException`:
`InvalidSignature`, `UnsupportedCurrency`, `GatewayUnavailable` and `UnsupportedOperation`.

## Security notes

- **Signatures are checked against the raw body.** Stripe and WaafiPay signatures are verified
  locally with `hash_equals()`; PayPal events are sent, byte for byte, to PayPal's
  verify-webhook-signature endpoint, and anything but `SUCCESS` is rejected.
- **Timestamps are enforced.** Stripe and WaafiPay signatures older or newer than the tolerance
  (300 seconds by default) are rejected, and a tolerance of zero is refused.
- **Replays are ignored** for `replay_ttl` seconds after the first successful processing.
- **`GatewayUnavailable` means "unknown".** A timeout after a request was sent may still have created
  a payment. Retry with the same `reference`, or reconcile with `find()`/webhooks, before charging
  again. WaafiPay has no idempotency key, so do not blindly retry its purchases.
- **Secrets stay out of traces.** Credentials are marked `#[SensitiveParameter]`; nothing in the
  package logs request bodies.
- **Only `2001` pays** on WaafiPay, and only an explicit paid state pays on Stripe and PayPal.
- **Webhook payloads are untrusted input** until verified; after verification they are read through
  a typed accessor so an unexpected shape cannot crash the handler halfway.

## Development

```bash
composer install
composer test        # Pest
composer lint:check  # Pint
composer analyse     # PHPStan (Larastan) at max level
```

The tests use `Http::fake()` with payloads modelled on each gateway's documented examples; they do
not call the real APIs.

## Credits

Built by [Mohammed Alashqar](mailto:mohammedname2002@gmail.com).
