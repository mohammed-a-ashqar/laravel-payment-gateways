<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Contracts;

use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Exceptions\GatewayException;

/**
 * Gateways where a payer's approval must be followed by an explicit capture.
 */
interface CapturesPayments
{
    /**
     * @throws GatewayException
     */
    public function capture(string $transactionId): PaymentResult;
}
