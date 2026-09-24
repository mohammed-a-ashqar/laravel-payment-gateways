<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\PayPal;

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Support\Payload;

final class PayPalStatus
{
    /**
     * An order is only as paid as its capture: a COMPLETED order can still hold a
     * PENDING or DECLINED capture, so the capture status wins.
     *
     * @see https://developer.paypal.com/docs/api/orders/v2/
     */
    public static function fromOrder(Payload $order): PaymentStatus
    {
        return match ($order->string('status')) {
            'CREATED', 'PAYER_ACTION_REQUIRED' => PaymentStatus::RequiresAction,
            'APPROVED' => PaymentStatus::Authorized,
            'VOIDED' => PaymentStatus::Canceled,
            'COMPLETED' => self::fromCapture($order->string('purchase_units.0.payments.captures.0.status')),
            default => PaymentStatus::Pending,
        };
    }

    public static function fromCapture(?string $status): PaymentStatus
    {
        return match ($status) {
            'COMPLETED' => PaymentStatus::Succeeded,
            'DECLINED', 'FAILED' => PaymentStatus::Failed,
            'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,
            'REFUNDED' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };
    }

    public static function fromRefund(?string $status): RefundStatus
    {
        return match ($status) {
            'COMPLETED' => RefundStatus::Succeeded,
            'FAILED', 'CANCELLED' => RefundStatus::Failed,
            default => RefundStatus::Pending,
        };
    }
}
