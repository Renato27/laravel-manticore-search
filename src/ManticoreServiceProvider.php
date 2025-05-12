<?php

namespace ManticoreLaravel;

use Illuminate\Support\ServiceProvider;

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
    }
}
