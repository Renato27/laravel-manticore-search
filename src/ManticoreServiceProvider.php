<?php

namespace ManticoreLaravel;

use Illuminate\Support\ServiceProvider;
use ManticoreLaravel\Support\ManticoreClientFactory;
use ManticoreLaravel\Support\ManticoreConnectionResolver;
use ManticoreLaravel\Support\ManticoreManager;

class ManticoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/Config/manticore.php' => config_path('manticore.php'),
        ], 'config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/manticore.php', 'manticore');

        $this->app->singleton(ManticoreConnectionResolver::class, function ($app) {
            return new ManticoreConnectionResolver($app['config']);
        });

        $this->app->singleton(ManticoreClientFactory::class, function () {
            return new ManticoreClientFactory();
        });

        $this->app->singleton(ManticoreManager::class, function ($app) {
            return new ManticoreManager(
                $app->make(ManticoreConnectionResolver::class),
                $app->make(ManticoreClientFactory::class),
            );
        });

        $this->app->alias(ManticoreManager::class, 'manticore');
    }
}
