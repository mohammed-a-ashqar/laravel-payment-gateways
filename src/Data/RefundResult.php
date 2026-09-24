<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Data;

use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Money;

final readonly class RefundResult
{
    /**
     * @param  string  $transactionId  The payment that was refunded.
     * @param  Money|null  $amount  Null when the gateway does not report the refunded amount.
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public RefundStatus $status,
        public ?string $refundId,
        public string $transactionId,
        public ?Money $amount = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === RefundStatus::Succeeded;
    }
}
