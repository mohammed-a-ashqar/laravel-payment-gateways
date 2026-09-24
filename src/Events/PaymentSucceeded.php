<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Events;

use Alashqar\PaymentGateways\Data\WebhookEvent;

final readonly class PaymentSucceeded
{
    public function __construct(public WebhookEvent $webhook) {}
}
