<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Data;

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Money;

final readonly class PaymentResult
{
    /**
     * @param  string|null  $transactionId  The gateway's id for the payment; null only when a
     *                                      failed attempt never received one.
     * @param  array<array-key, mixed>  $raw  The decoded gateway response, for logging and debugging.
     */
    public function __construct(
        public PaymentStatus $status,
        public ?string $transactionId,
        public ?Money $amount = null,
        public ?string $redirectUrl = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null && $this->status === PaymentStatus::RequiresAction;
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }
}
