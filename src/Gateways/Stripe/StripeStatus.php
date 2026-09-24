<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\Stripe;

use Alashqar\PaymentGateways\Enums\PaymentStatus;

/**
 * @see https://docs.stripe.com/api/checkout/sessions/object
 */
final class StripeStatus
{
    /**
     * A session is only paid when Stripe says so explicitly. "complete" with
     * "unpaid" happens for delayed methods (e.g. bank debits) that settle later.
     */
    public static function fromCheckoutSession(?string $status, ?string $paymentStatus): PaymentStatus
    {
        return match (true) {
            $status === 'complete' && in_array($paymentStatus, ['paid', 'no_payment_required'], true) => PaymentStatus::Succeeded,
            $status === 'complete' => PaymentStatus::Pending,
            $status === 'open' => PaymentStatus::RequiresAction,
            $status === 'expired' => PaymentStatus::Canceled,
            default => PaymentStatus::Pending,
        };
    }
}
