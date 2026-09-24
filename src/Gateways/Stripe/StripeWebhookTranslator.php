<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\Stripe;

use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\Payload;

/**
 * Turns a verified Stripe Event into a WebhookEvent.
 *
 * Checkout events carry the Checkout Session id as the transaction id. Refund
 * events ("charge.refunded") carry the PaymentIntent id, because a Charge does not
 * reference its Checkout Session; the payment reference from metadata is the
 * reliable key to correlate both.
 *
 * @see https://docs.stripe.com/api/events/types
 */
final class StripeWebhookTranslator
{
    public function translate(Payload $event): WebhookEvent
    {
        $id = $event->string('id');
        $type = $event->string('type');

        if ($id === null || $type === null) {
            throw new GatewayException('The Stripe webhook payload is not an Event object.', 'stripe');
        }

        $object = $event->get('data.object');

        if ($type === 'charge.refunded') {
            return new WebhookEvent(
                id: $id,
                gateway: 'stripe',
                type: $type,
                status: $object->bool('refunded') === true ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                transactionId: $object->string('payment_intent'),
                reference: $object->string('metadata.reference'),
                amount: $this->money($object->int('amount_refunded'), $object->string('currency')),
                payload: $event->all(),
            );
        }

        $status = match ($type) {
            'checkout.session.completed' => StripeStatus::fromCheckoutSession(
                $object->string('status'),
                $object->string('payment_status'),
            ),
            'checkout.session.async_payment_succeeded' => PaymentStatus::Succeeded,
            'checkout.session.async_payment_failed' => PaymentStatus::Failed,
            'checkout.session.expired' => PaymentStatus::Canceled,
            default => null,
        };

        return new WebhookEvent(
            id: $id,
            gateway: 'stripe',
            type: $type,
            status: $status,
            transactionId: $object->string('id'),
            reference: $object->string('client_reference_id') ?? $object->string('metadata.reference'),
            amount: $this->money($object->int('amount_total'), $object->string('currency')),
            payload: $event->all(),
        );
    }

    private function money(?int $amount, ?string $currency): ?Money
    {
        return $amount !== null && $currency !== null ? Money::of($amount, $currency) : null;
    }
}
