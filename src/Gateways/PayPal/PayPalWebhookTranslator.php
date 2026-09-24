<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\PayPal;

use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Support\Payload;

/**
 * Turns a verified PayPal webhook into a WebhookEvent.
 *
 * Capture events carry the capture as their resource; the owning order id is read
 * from supplementary_data so the transaction id matches the one returned by
 * createPayment(). PayPal's refund events do not say whether the capture is now
 * fully refunded, so they are reported as Refunded; call find() for the exact state.
 *
 * @see https://developer.paypal.com/api/rest/webhooks/event-names/
 */
final class PayPalWebhookTranslator
{
    public function translate(Payload $event): WebhookEvent
    {
        $id = $event->string('id');
        $type = $event->string('event_type');

        if ($id === null || $type === null) {
            throw new GatewayException('The PayPal webhook payload is not an event.', 'paypal');
        }

        $resource = $event->get('resource');

        $status = match ($type) {
            'CHECKOUT.ORDER.APPROVED' => PaymentStatus::Authorized,
            'PAYMENT.CAPTURE.COMPLETED' => PaymentStatus::Succeeded,
            'PAYMENT.CAPTURE.PENDING' => PaymentStatus::Pending,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => PaymentStatus::Failed,
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => PaymentStatus::Refunded,
            default => null,
        };

        return new WebhookEvent(
            id: $id,
            gateway: 'paypal',
            type: $type,
            status: $status,
            transactionId: $resource->string('supplementary_data.related_ids.order_id') ?? $resource->string('id'),
            reference: $resource->string('custom_id') ?? $resource->string('purchase_units.0.custom_id'),
            amount: PayPalAmount::fromPayPal($resource->get('amount'))
                ?? PayPalAmount::fromPayPal($resource->get('purchase_units.0.amount')),
            payload: $event->all(),
        );
    }
}
