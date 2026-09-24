<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Testing;

use Alashqar\PaymentGateways\Contracts\AuthorizesPayments;
use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Data\RefundResult;
use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\Payload;
use Closure;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * An in-memory gateway for your application's tests, in the spirit of Mail::fake().
 *
 *     $payments = Payments::fake();
 *     // ... exercise your checkout ...
 *     $payments->assertCharged(fn (PaymentRequest $r) => $r->amount->equals(Money::of(2500, 'USD')));
 *
 * Its parseWebhook() does not check signatures and must never be used in production.
 */
final class FakeGateway implements AuthorizesPayments, Gateway
{
    /** @var list<PaymentRequest> */
    private array $charges = [];

    /** @var list<PaymentRequest> */
    private array $authorizations = [];

    /** @var list<array{transactionId: string, amount: Money}> */
    private array $refunds = [];

    /** @var array<string, PaymentResult> */
    private array $payments = [];

    private PaymentStatus $outcome = PaymentStatus::Succeeded;

    private ?string $failureReason = null;

    private ?Throwable $exception = null;

    private int $sequence = 0;

    public function __construct(private readonly string $name = 'fake') {}

    public function name(): string
    {
        return $this->name;
    }

    public function willSucceed(): self
    {
        return $this->respondWith(PaymentStatus::Succeeded);
    }

    public function willFail(string $reason = 'card_declined'): self
    {
        return $this->respondWith(PaymentStatus::Failed, $reason);
    }

    public function willRequireAction(): self
    {
        return $this->respondWith(PaymentStatus::RequiresAction);
    }

    /**
     * Make every following call throw, e.g. to test your GatewayUnavailable handling.
     */
    public function willThrow(Throwable $exception): self
    {
        $this->exception = $exception;

        return $this;
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $this->throwIfConfigured();
        $this->charges[] = $request;

        return $this->store($request, $this->outcome);
    }

    public function authorize(PaymentRequest $request): PaymentResult
    {
        $this->throwIfConfigured();
        $this->authorizations[] = $request;

        return $this->store($request, $this->outcome === PaymentStatus::Succeeded ? PaymentStatus::Authorized : $this->outcome);
    }

    public function capture(string $transactionId): PaymentResult
    {
        return $this->move($transactionId, PaymentStatus::Succeeded);
    }

    public function void(string $transactionId): PaymentResult
    {
        return $this->move($transactionId, PaymentStatus::Canceled);
    }

    public function find(string $transactionId): PaymentResult
    {
        $this->throwIfConfigured();

        return $this->payments[$transactionId]
            ?? throw new GatewayException("Unknown fake transaction [{$transactionId}].", $this->name);
    }

    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult
    {
        $original = $this->find($transactionId)->amount
            ?? throw new GatewayException("Fake transaction [{$transactionId}] has no amount.", $this->name);

        $refund = $amount ?? $original->minus($this->refundedSoFar($transactionId, $original->currency));
        $total = $this->refundedSoFar($transactionId, $original->currency)->plus($refund);

        if ($total->greaterThan($original)) {
            throw new GatewayException("Refunding {$refund->format()} would exceed the original payment.", $this->name);
        }

        $this->move($transactionId, $total->equals($original) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded);
        $this->refunds[] = ['transactionId' => $transactionId, 'amount' => $refund];

        return new RefundResult(RefundStatus::Succeeded, 'fake_refund_'.count($this->refunds), $transactionId, $refund);
    }

    /**
     * Accepts a JSON body such as {"id": "evt_1", "type": "paid", "status": "succeeded",
     * "transaction_id": "fake_1", "reference": "order_1"} without any signature.
     */
    public function parseWebhook(Request $request): WebhookEvent
    {
        $this->throwIfConfigured();

        $payload = Payload::fromJson($request->getContent());
        $status = $payload->string('status');

        return new WebhookEvent(
            id: $payload->string('id') ?? 'fake_evt_'.(++$this->sequence),
            gateway: $this->name,
            type: $payload->string('type') ?? 'fake',
            status: $status !== null ? PaymentStatus::tryFrom($status) : null,
            transactionId: $payload->string('transaction_id'),
            reference: $payload->string('reference'),
            payload: $payload->all(),
        );
    }

    /**
     * @param  (Closure(PaymentRequest): bool)|Money|null  $expectation
     */
    public function assertCharged(Closure|Money|null $expectation = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->matching($this->charges, $expectation),
            'The expected payment was not charged.',
        );
    }

    /**
     * @param  (Closure(PaymentRequest): bool)|Money  $expectation
     */
    public function assertNotCharged(Closure|Money $expectation): void
    {
        PHPUnit::assertEmpty(
            $this->matching($this->charges, $expectation),
            'An unexpected payment was charged.',
        );
    }

    public function assertChargedTimes(int $times): void
    {
        PHPUnit::assertCount($times, $this->charges, "Expected {$times} charge(s), got ".count($this->charges).'.');
    }

    public function assertNothingCharged(): void
    {
        PHPUnit::assertEmpty($this->charges, 'Payments were charged unexpectedly.');
    }

    /**
     * @param  (Closure(PaymentRequest): bool)|Money|null  $expectation
     */
    public function assertAuthorized(Closure|Money|null $expectation = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->matching($this->authorizations, $expectation),
            'The expected payment was not authorized.',
        );
    }

    /**
     * @param  (Closure(string, Money): bool)|string|null  $expectation  A transaction id or a callback.
     */
    public function assertRefunded(Closure|string|null $expectation = null): void
    {
        $matches = array_filter($this->refunds, fn (array $refund): bool => match (true) {
            $expectation === null => true,
            is_string($expectation) => $refund['transactionId'] === $expectation,
            default => $expectation($refund['transactionId'], $refund['amount']) === true,
        });

        PHPUnit::assertNotEmpty($matches, 'The expected refund was not issued.');
    }

    public function assertNothingRefunded(): void
    {
        PHPUnit::assertEmpty($this->refunds, 'Refunds were issued unexpectedly.');
    }

    /**
     * @return list<PaymentRequest>
     */
    public function charges(): array
    {
        return $this->charges;
    }

    private function respondWith(PaymentStatus $status, ?string $failureReason = null): self
    {
        $this->outcome = $status;
        $this->failureReason = $failureReason;
        $this->exception = null;

        return $this;
    }

    private function throwIfConfigured(): void
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
    }

    private function store(PaymentRequest $request, PaymentStatus $status): PaymentResult
    {
        $id = $this->name.'_'.(++$this->sequence);

        return $this->payments[$id] = new PaymentResult(
            status: $status,
            transactionId: $id,
            amount: $request->amount,
            redirectUrl: $status === PaymentStatus::RequiresAction ? "https://fake-gateway.test/pay/{$id}" : null,
            failureReason: $status === PaymentStatus::Failed ? $this->failureReason : null,
        );
    }

    /**
     * Status changes go through the real transition guard, so a test that refunds a
     * failed payment fails here just like it would against a real gateway.
     */
    private function move(string $transactionId, PaymentStatus $next): PaymentResult
    {
        $payment = $this->find($transactionId);

        return $this->payments[$transactionId] = new PaymentResult(
            status: $payment->status->transitionTo($next),
            transactionId: $transactionId,
            amount: $payment->amount,
        );
    }

    private function refundedSoFar(string $transactionId, string $currency): Money
    {
        $total = Money::of(0, $currency);

        foreach ($this->refunds as $refund) {
            if ($refund['transactionId'] === $transactionId) {
                $total = $total->plus($refund['amount']);
            }
        }

        return $total;
    }

    /**
     * @param  list<PaymentRequest>  $requests
     * @param  (Closure(PaymentRequest): bool)|Money|null  $expectation
     * @return list<PaymentRequest>
     */
    private function matching(array $requests, Closure|Money|null $expectation): array
    {
        return array_values(array_filter($requests, fn (PaymentRequest $request): bool => match (true) {
            $expectation === null => true,
            $expectation instanceof Money => $request->amount->equals($expectation),
            default => $expectation($request) === true,
        }));
    }
}
