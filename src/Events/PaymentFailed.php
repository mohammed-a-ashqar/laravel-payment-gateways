<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Events;

use Alashqar\PaymentGateways\Data\WebhookEvent;

/**
 * Dispatched for failed and for canceled/expired payments; check
 * $webhook->status to tell them apart.
 */
final readonly class PaymentFailed
{
    public function __construct(public WebhookEvent $webhook) {}
}
