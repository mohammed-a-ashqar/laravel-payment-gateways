<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Contracts;

use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Data\RefundResult;
use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\InvalidSignature;
use Alashqar\PaymentGateways\Money;
use Illuminate\Http\Request;

interface Gateway
{
    /**
     * The name the gateway is registered under, e.g. "stripe".
     */
    public function name(): string;

    /**
     * Start a payment. A declined payment is returned as a failed result; exceptions
     * are reserved for errors where the outcome is unknown or the request was invalid.
     *
     * @throws GatewayException
     */
    public function createPayment(PaymentRequest $request): PaymentResult;

    /**
     * Fetch the current state of a payment from the gateway.
     *
     * @throws GatewayException
     */
    public function find(string $transactionId): PaymentResult;

    /**
     * Refund a payment in full, or partially when an amount is given.
     *
     * @param  string|null  $idempotencyKey  Reuse the same key when retrying the same refund.
     *
     * @throws GatewayException
     */
    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult;

    /**
     * Verify an incoming webhook and translate it into a WebhookEvent.
     *
     * @throws InvalidSignature when the request cannot be proven to come from the gateway.
     * @throws GatewayException
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
