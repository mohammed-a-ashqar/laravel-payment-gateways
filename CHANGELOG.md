# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-09-24

### Added

- `Money` value object with integer minor units, exact decimal parsing and currency guards.
- `PaymentStatus` enum with a transition guard, and `RefundStatus`.
- `Gateway`, `CapturesPayments` and `AuthorizesPayments` contracts with `PaymentRequest`,
  `PaymentResult`, `RefundResult` and `WebhookEvent` data objects.
- `PaymentManager` built on `Illuminate\Support\Manager`, the `Payments` facade and
  `Payments::extend()` for custom drivers.
- Shared HTTP client with timeouts and connection-only retries.
- Stripe driver: Checkout Sessions, lookups, full and partial refunds, `Stripe-Signature` verification.
- PayPal driver: Orders v2 create/capture, refunds, cached OAuth tokens, webhook verification
  through PayPal's API.
- WaafiPay driver: `API_PURCHASE`, `API_PREAUTHORIZE` with commit/cancel, `API_REVERSAL`, and
  HMAC verification of Hosted Payment Page webhooks.
- `FakeGateway` and `Payments::fake()` for application tests.
- Optional webhook route with replay protection and `PaymentSucceeded`, `PaymentFailed`,
  `PaymentRefunded` and `WebhookReceived` events.

[0.1.0]: https://github.com/mohammed-a-ashqar/laravel-payment-gateways/releases/tag/v0.1.0
