<?php

namespace Tests;

use ManticoreLaravel\ManticoreServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ManticoreServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('manticore.default', 'default');
        $app['config']->set('manticore.connections.default', [
            'host'        => '127.0.0.1',
            'port'        => 9312,
            'username'    => 'root',
            'password'    => null,
            'transport'   => 'Http',
            'timeout'     => 5,
            'persistent'  => false,
            'max_matches' => 1000,
        ]);

        $app['config']->set('manticore.connections.analytics', [
            'host'        => '10.0.0.2',
            'port'        => 9312,
            'username'    => null,
            'password'    => null,
            'transport'   => 'Http',
            'timeout'     => 5,
            'persistent'  => false,
            'max_matches' => 2000,
        ]);
    }
}
