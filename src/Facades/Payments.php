<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Facades;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\PaymentManager;
use Alashqar\PaymentGateways\Support\GatewayClient;
use Alashqar\PaymentGateways\Testing\FakeGateway;
use Alashqar\PaymentGateways\Testing\FakePaymentManager;
use Closure;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static Gateway gateway(?string $name = null)
 * @method static mixed driver(?string $driver = null)
 * @method static PaymentManager extend(string $driver, Closure $callback)
 * @method static string getDefaultDriver()
 * @method static array<array-key, mixed> gatewayConfig(string $name)
 * @method static GatewayClient client(string $name)
 *
 * @see PaymentManager
 */
final class Payments extends Facade
{
    /**
     * Replace every gateway with an in-memory fake for the rest of the test.
     */
    public static function fake(): FakeGateway
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new RuntimeException('Payments::fake() needs a booted Laravel application.');
        }

        $fake = new FakeGateway;

        self::swap(new FakePaymentManager($app, $fake));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
