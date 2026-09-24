<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Http\Controllers;

use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Events\PaymentFailed;
use Alashqar\PaymentGateways\Events\PaymentRefunded;
use Alashqar\PaymentGateways\Events\PaymentSucceeded;
use Alashqar\PaymentGateways\Events\WebhookReceived;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use Alashqar\PaymentGateways\PaymentManager;
use Alashqar\PaymentGateways\Webhooks\ReplayGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * POST {path}/{gateway}: verify, de-duplicate, then dispatch Laravel events.
 *
 * Status codes are chosen for the gateways' retry logic: 4xx for requests that
 * will never succeed (unknown gateway, bad signature), 503 when verification
 * itself could not complete so the gateway delivers again later.
 */
final readonly class WebhookController
{
    public function __construct(
        private PaymentManager $payments,
        private ReplayGuard $replayGuard,
        private Dispatcher $events,
    ) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        try {
            $driver = $this->payments->gateway($gateway);
        } catch (InvalidConfiguration $exception) {
            throw $exception;
        } catch (InvalidArgumentException) {
            return new JsonResponse(['message' => 'Unknown payment gateway.'], 404);
        }

        try {
            $event = $driver->parseWebhook($request);
        } catch (InvalidSignature) {
            return new JsonResponse(['message' => 'Invalid signature.'], 400);
        } catch (GatewayUnavailable) {
            return new JsonResponse(['message' => 'Verification temporarily unavailable.'], 503);
        }

        if (! $this->replayGuard->claim($event)) {
            return new JsonResponse(['message' => 'Already processed.']);
        }

        try {
            $this->dispatch($event);
        } catch (Throwable $exception) {
            $this->replayGuard->release($event);

            throw $exception;
        }

        return new JsonResponse(['message' => 'Processed.']);
    }

    private function dispatch(WebhookEvent $event): void
    {
        $this->events->dispatch(new WebhookReceived($event));

        $specific = match ($event->status) {
            PaymentStatus::Succeeded => new PaymentSucceeded($event),
            PaymentStatus::Failed, PaymentStatus::Canceled => new PaymentFailed($event),
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => new PaymentRefunded($event),
            default => null,
        };

        if ($specific !== null) {
            $this->events->dispatch($specific);
        }
    }
}
