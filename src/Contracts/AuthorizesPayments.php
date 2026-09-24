<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Contracts;

use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Exceptions\GatewayException;

/**
 * Gateways that can place a hold on funds and later capture or release it.
 */
interface AuthorizesPayments extends CapturesPayments
{
    /**
     * @throws GatewayException
     */
    public function authorize(PaymentRequest $request): PaymentResult;

    /**
     * Release a hold without collecting any money.
     *
     * @throws GatewayException
     */
    public function void(string $transactionId): PaymentResult;
}
