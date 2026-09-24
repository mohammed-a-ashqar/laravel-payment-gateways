<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\WaafiPay;

use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Verifies WaafiPay webhook signatures: a lowercase hex HMAC-SHA256 of
 * "{X-Webhook-Timestamp}.{X-Webhook-Event-Id}.{raw body}" with the merchant's
 * webhook secret, plus a freshness check on the timestamp.
 *
 * @see https://docs.waafipay.com/webhooks
 */
final readonly class WaafiPaySignature
{
    public function __construct(
        #[SensitiveParameter] private string $secret,
        private int $toleranceSeconds = 300,
    ) {
        if ($toleranceSeconds < 1) {
            throw new InvalidArgumentException('The WaafiPay webhook tolerance must be at least one second.');
        }
    }

    /**
     * @throws InvalidSignature
     */
    public function verify(string $body, ?string $timestamp, ?string $eventId, ?string $signature, int $now): void
    {
        if ($timestamp === null || $eventId === null || $eventId === '' || $signature === null || $signature === '') {
            throw InvalidSignature::because('waafipay', 'the X-Webhook-Timestamp, X-Webhook-Event-Id or X-Webhook-Signature header is missing.');
        }

        if (! ctype_digit($timestamp)) {
            throw InvalidSignature::because('waafipay', 'the timestamp is not a Unix timestamp.');
        }

        if (! hash_equals(self::sign($body, $this->secret, (int) $timestamp, $eventId), strtolower($signature))) {
            throw InvalidSignature::because('waafipay', 'the signature does not match the payload.');
        }

        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            throw InvalidSignature::because('waafipay', 'the timestamp is outside the tolerance window.');
        }
    }

    public static function sign(string $body, #[SensitiveParameter] string $secret, int $timestamp, string $eventId): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.$body, $secret);
    }
}
