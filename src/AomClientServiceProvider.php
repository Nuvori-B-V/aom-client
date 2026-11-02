<?php

namespace Nuvori\AomClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AomClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aom.php', 'aom');

        $this->app->singleton(Aom::class);
        $this->app->singleton(ErrorCollector::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/aom.php' => config_path('aom.php')], 'aom-config');

        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (Throwable $exception): void {
                $this->app->make(ErrorCollector::class)->capture($exception);
            });
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(ErrorCollector::class)->flush())
                ->name('aom:send-errors')
                ->everyMinute();
        });
    }
}
