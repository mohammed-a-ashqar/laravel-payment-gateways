<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Testing;

use Alashqar\PaymentGateways\PaymentManager;
use Illuminate\Contracts\Container\Container;

/**
 * Hands out the same FakeGateway for every gateway name, so application code
 * that asks for "stripe" or "waafipay" keeps working unchanged under test.
 */
final class FakePaymentManager extends PaymentManager
{
    public function __construct(Container $container, private readonly FakeGateway $fake)
    {
        parent::__construct($container);
    }

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): FakeGateway
    {
        return $this->fake;
    }
}
