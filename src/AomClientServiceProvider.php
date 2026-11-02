<?php

namespace Nuvori\AomClient;

use Illuminate\Support\ServiceProvider;

class AomClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aom.php', 'aom');

        $this->app->singleton(Aom::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/aom.php' => config_path('aom.php')], 'aom-config');
    }
}
