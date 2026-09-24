<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\WaafiPay;

use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\Payload;
use InvalidArgumentException;

/**
 * @see https://docs.waafipay.com/webhooks
 */
final class WaafiPayWebhookTranslator
{
    public function translate(string $eventId, Payload $event): WebhookEvent
    {
        $type = $event->string('event');

        if ($type === null) {
            throw new GatewayException('The WaafiPay webhook payload has no event name.', 'waafipay');
        }

        $payment = $event->get('payment');
        $state = strtoupper($payment->string('status') ?? '');

        $status = match (true) {
            $type === 'authorization' && $state === 'APPROVED' => PaymentStatus::Succeeded,
            $type === 'authorization' && in_array($state, ['FAILED', 'DECLINED'], true) => PaymentStatus::Failed,
            $type === 'authorization' && in_array($state, ['CANCELED', 'EXPIRED', 'TIMEOUT'], true) => PaymentStatus::Canceled,
            $type === 'refund' && $state === 'APPROVED' => PaymentStatus::Refunded,
            default => null,
        };

        return new WebhookEvent(
            id: $eventId,
            gateway: 'waafipay',
            type: $type,
            status: $status,
            transactionId: $payment->string('transaction_id'),
            reference: $payment->string('reference_id'),
            amount: $this->money($payment->string('amount'), $payment->string('currency')),
            payload: $event->all(),
        );
    }

    private function money(?string $amount, ?string $currency): ?Money
    {
        if ($amount === null || $currency === null) {
            return null;
        }

        try {
            return Money::fromDecimal($amount, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
