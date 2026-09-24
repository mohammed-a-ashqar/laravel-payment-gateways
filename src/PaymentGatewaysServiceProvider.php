<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class PaymentGatewaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-gateways.php', 'payment-gateways');

        $this->app->singleton(PaymentManager::class, fn (Application $app) => new PaymentManager($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payment-gateways.php' => $this->app->configPath('payment-gateways.php'),
            ], 'payment-gateways-config');
        }
    }
}
