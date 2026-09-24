<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\Stripe;

use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use InvalidArgumentException;

/**
 * Verifies the Stripe-Signature header without the Stripe SDK.
 *
 * Header format: "t=1492774577,v1=5257a8...,v0=6ffbb5...". The signed payload is
 * "{t}.{raw body}", signed with HMAC-SHA256 using the endpoint secret. Only the v1
 * scheme is accepted, to prevent downgrade attacks, and there may be several v1
 * signatures while an endpoint secret is being rolled.
 *
 * @see https://docs.stripe.com/webhooks?verify=verify-manually
 */
final readonly class StripeSignature
{
    public function __construct(
        private string $secret,
        private int $toleranceSeconds = 300,
    ) {
        // A zero tolerance would silently disable replay protection.
        if ($toleranceSeconds < 1) {
            throw new InvalidArgumentException('The Stripe webhook tolerance must be at least one second.');
        }
    }

    /**
     * @throws InvalidSignature
     */
    public function verify(string $payload, ?string $header, int $now): void
    {
        if ($header === null || trim($header) === '') {
            throw InvalidSignature::because('stripe', 'the Stripe-Signature header is missing.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $element) {
            [$prefix, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            if ($prefix === 't') {
                $timestamp = $value;
            } elseif ($prefix === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            throw InvalidSignature::because('stripe', 'the header has no valid timestamp.');
        }

        if ($signatures === []) {
            throw InvalidSignature::because('stripe', 'the header has no v1 signature.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        $matches = array_filter($signatures, fn (string $signature): bool => hash_equals($expected, $signature));

        if ($matches === []) {
            throw InvalidSignature::because('stripe', 'no signature matches the payload.');
        }

        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            throw InvalidSignature::because('stripe', 'the timestamp is outside the tolerance window.');
        }
    }

    /**
     * Build a header the way Stripe does; used to test webhook handlers.
     */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
