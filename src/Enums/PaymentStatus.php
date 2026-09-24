<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Enums;

use Alashqar\PaymentGateways\Exceptions\InvalidStatusTransition;

enum PaymentStatus: string
{
    /** Created at the gateway, nothing has been collected yet. */
    case Pending = 'pending';

    /** The payer must do something (approve on PayPal, finish Stripe Checkout...). */
    case RequiresAction = 'requires_action';

    /** Funds are approved or held but must still be captured by the merchant. */
    case Authorized = 'authorized';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** Abandoned, expired or voided before any money moved. */
    case Canceled = 'canceled';

    case PartiallyRefunded = 'partially_refunded';

    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::RequiresAction, self::Authorized, self::Succeeded, self::Failed, self::Canceled],
            self::RequiresAction => [self::Pending, self::Authorized, self::Succeeded, self::Failed, self::Canceled],
            self::Authorized => [self::Succeeded, self::Failed, self::Canceled],
            self::Succeeded => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Failed, self::Canceled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Guards a status change coming from a webhook or a lookup, so that e.g. a late
     * "failed" notification can never overwrite a payment that already succeeded.
     *
     * @throws InvalidStatusTransition
     */
    public function transitionTo(self $next): self
    {
        if (! $this->canTransitionTo($next)) {
            throw InvalidStatusTransition::from($this, $next);
        }

        return $next;
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether money has been collected (and not fully returned).
     */
    public function isPaid(): bool
    {
        return $this === self::Succeeded || $this === self::PartiallyRefunded;
    }
}
