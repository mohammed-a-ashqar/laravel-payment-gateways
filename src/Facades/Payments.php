<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Facades;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\PaymentManager;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Gateway gateway(?string $name = null)
 * @method static mixed driver(?string $driver = null)
 * @method static PaymentManager extend(string $driver, Closure $callback)
 * @method static string getDefaultDriver()
 * @method static array<string, mixed> gatewayConfig(string $name)
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
