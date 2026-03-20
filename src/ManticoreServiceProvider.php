<?php

namespace ManticoreLaravel;

use Illuminate\Support\ServiceProvider;
use ManticoreLaravel\Support\ManticoreClientFactory;
use ManticoreLaravel\Support\ManticoreConnectionResolver;

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
    }
}
