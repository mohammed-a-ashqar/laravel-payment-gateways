<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Events;

use Alashqar\PaymentGateways\Data\WebhookEvent;

/**
 * Dispatched for every verified, first-time webhook, including event types this
 * package does not map to a payment status.
 */
final readonly class WebhookReceived
{
    public function __construct(public WebhookEvent $webhook) {}
}
