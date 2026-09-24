<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Facades;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\PaymentManager;
use Alashqar\PaymentGateways\Support\GatewayClient;
use Closure;
use Illuminate\Support\Facades\Facade;

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
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
