<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Tests\Fixtures;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Data\RefundResult;
use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Request;

/**
 * A minimal third-party style driver used to exercise Payments::extend().
 */
final class InMemoryGateway implements Gateway
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public readonly array $config = []) {}

    public function name(): string
    {
        return 'in-memory';
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        return new PaymentResult(PaymentStatus::Succeeded, 'mem_'.$request->reference, $request->amount);
    }

    public function find(string $transactionId): PaymentResult
    {
        return new PaymentResult(PaymentStatus::Succeeded, $transactionId);
    }

    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult
    {
        return new RefundResult(RefundStatus::Succeeded, 'ref_1', $transactionId, $amount);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        return new WebhookEvent('evt_1', 'in-memory', 'test', PaymentStatus::Succeeded);
    }
}
