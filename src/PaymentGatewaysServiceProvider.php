<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways;

use Alashqar\PaymentGateways\Http\Controllers\WebhookController;
use Alashqar\PaymentGateways\Webhooks\ReplayGuard;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class PaymentGatewaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-gateways.php', 'payment-gateways');

        $this->app->singleton(PaymentManager::class, fn (Application $app) => new PaymentManager($app));

        $this->app->singleton(ReplayGuard::class, function (Application $app): ReplayGuard {
            $config = $app->make(Config::class);
            $store = $config->get('payment-gateways.webhooks.cache_store');
            $ttl = $config->get('payment-gateways.webhooks.replay_ttl', 86400);

            return new ReplayGuard(
                $app->make(CacheFactory::class)->store(is_string($store) ? $store : null),
                is_numeric($ttl) ? (int) $ttl : 86400,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payment-gateways.php' => $this->app->configPath('payment-gateways.php'),
            ], 'payment-gateways-config');
        }

        $this->registerWebhookRoute();
    }

    private function registerWebhookRoute(): void
    {
        $config = $this->app->make(Config::class);

        if (! filter_var($config->get('payment-gateways.webhooks.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $path = $config->get('payment-gateways.webhooks.path');
        $middleware = $config->get('payment-gateways.webhooks.middleware', []);

        $this->app->make(Router::class)
            ->post(trim(is_string($path) ? $path : 'payment-gateways/webhooks', '/').'/{gateway}', WebhookController::class)
            ->middleware(array_values(array_filter(is_array($middleware) ? $middleware : [], is_string(...))))
            ->where('gateway', '[A-Za-z0-9_-]+')
            ->name('payment-gateways.webhook');
    }
}
