<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Tests;

use Alashqar\PaymentGateways\PaymentGatewaysServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PaymentGatewaysServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }
}
