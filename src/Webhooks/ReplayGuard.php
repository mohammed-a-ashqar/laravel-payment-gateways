<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Webhooks;

use Alashqar\PaymentGateways\Data\WebhookEvent;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Remembers processed webhook ids so a redelivered or replayed event is only
 * handled once. Gateways retry deliveries and do not guarantee exactly-once.
 *
 * Cache::add() is atomic on the stores that matter in production (Redis,
 * Memcached, database), so two concurrent deliveries cannot both claim an event.
 */
final readonly class ReplayGuard
{
    public function __construct(private Cache $cache, private int $ttlSeconds) {}

    public function claim(WebhookEvent $event): bool
    {
        return $this->cache->add($this->key($event), true, $this->ttlSeconds);
    }

    /**
     * Give the event back when handling failed, so the gateway's retry is processed.
     */
    public function release(WebhookEvent $event): void
    {
        $this->cache->forget($this->key($event));
    }

    private function key(WebhookEvent $event): string
    {
        return 'payment-gateways:webhook:'.$event->gateway.':'.hash('sha256', $event->id);
    }
}
