<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Data;

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Money;

/**
 * A verified, gateway-agnostic view of an incoming webhook.
 *
 * Only constructed after the signature has been checked, so listeners can trust it.
 */
final readonly class WebhookEvent
{
    /**
     * @param  string  $id  The gateway's unique event id, used for replay protection.
     * @param  string  $type  The gateway's own event name, e.g. "checkout.session.completed".
     * @param  PaymentStatus|null  $status  Null for events that do not change a payment's status.
     * @param  string|null  $reference  Your PaymentRequest reference, when the gateway echoes it back.
     * @param  array<array-key, mixed>  $payload  The decoded webhook body.
     */
    public function __construct(
        public string $id,
        public string $gateway,
        public string $type,
        public ?PaymentStatus $status,
        public ?string $transactionId = null,
        public ?string $reference = null,
        public ?Money $amount = null,
        public array $payload = [],
    ) {}
}
