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
            'host'        => env('MANTICORE_HOST', '127.0.0.1'),
            'port'        => (int) env('MANTICORE_PORT', 9308),
            'username'    => null,
            'password'    => null,
            'transport'   => 'Http',
            'timeout'     => 5,
            'persistent'  => false,
            'max_matches' => 1000,
        ]);

        $app['config']->set('manticore.connections.analytics', [
            'host'        => '10.0.0.2',
            'port'        => 9308,
            'username'    => null,
            'password'    => null,
            'transport'   => 'Http',
            'timeout'     => 5,
            'persistent'  => false,
            'max_matches' => 2000,
        ]);

        $app['config']->set('manticore.unlimited_max_matches', 1000000);
        $app['config']->set('manticore.pagination.cache_prefix', 'manticore:pagination:');
        $app['config']->set('manticore.pagination.total_cache_ttl', 300);
        $app['config']->set('manticore.pagination.context_key', '_mctx');
        $app['config']->set('manticore.pagination.context_ttl', 900);
        $app['config']->set('manticore.pagination.max_query_length', 1500);
    }
}
