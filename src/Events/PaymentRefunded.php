<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Events;

use Alashqar\PaymentGateways\Data\WebhookEvent;

/**
 * Dispatched for full and partial refunds; $webhook->status tells them apart.
 */
final readonly class PaymentRefunded
{
    public function __construct(public WebhookEvent $webhook) {}
}
