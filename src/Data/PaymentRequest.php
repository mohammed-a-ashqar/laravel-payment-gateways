<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Data;

use Alashqar\PaymentGateways\Money;
use InvalidArgumentException;

final readonly class PaymentRequest
{
    /**
     * @param  string  $reference  Your own order/payment id. It is sent to every gateway as the
     *                             idempotency key and merchant reference, so retrying with the same
     *                             reference can never create a second charge on gateways that
     *                             support idempotency (Stripe, PayPal).
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public Money $amount,
        public string $reference,
        public ?string $description = null,
        public ?Customer $customer = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
    ) {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A payment amount must be greater than zero.');
        }

        // The strictest gateway rules win: WaafiPay only allows these characters and
        // PayPal caps custom_id / invoice_id at 127 characters.
        if (preg_match('/^[A-Za-z0-9._-]{1,127}$/', $reference) !== 1) {
            throw new InvalidArgumentException(
                'A payment reference must be 1-127 characters of letters, digits, dots, dashes or underscores.'
            );
        }

        foreach ([$returnUrl, $cancelUrl] as $url) {
            if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException("[{$url}] is not a valid URL.");
            }
        }

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new InvalidArgumentException('Payment metadata must be a map of strings to strings.');
            }
        }
    }
}
