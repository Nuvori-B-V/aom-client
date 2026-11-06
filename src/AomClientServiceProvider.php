<?php

namespace Nuvori\AomClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AomClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aom.php', 'aom');

        $this->app->singleton(Aom::class);
        $this->app->singleton(ErrorCollector::class);
        $this->app->singleton(DailyReport::class);
        $this->app->singleton(HealthCheck::class);
        $this->app->singleton(Feedback::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/aom.php' => config_path('aom.php')], 'aom-config');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'aom');

        Route::get('aom/health', fn () => $this->app->make(HealthCheck::class)->response())->name('aom.health');
        Route::get('aom/feedback.js', fn () => $this->app->make(Feedback::class)->script())->name('aom.feedback.script');
        Route::get('aom/feedback.css', fn () => $this->app->make(Feedback::class)->style())->name('aom.feedback.style');
        Route::post('aom/feedback', fn (Request $request) => $this->app->make(Feedback::class)->send($request))
            ->middleware(['web', 'auth'])
            ->name('aom.feedback');

        Event::listen(Looping::class, fn () => $this->app->make(HealthCheck::class)->beat(HealthCheck::QUEUE_HEARTBEAT));

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

            $schedule->call(fn () => $this->app->make(HealthCheck::class)->beat(HealthCheck::SCHEDULER_HEARTBEAT))
                ->name('aom:scheduler-heartbeat')
                ->everyMinute();

            $schedule->call(fn () => $this->app->make(DailyReport::class)->send())
                ->name('aom:send-report')
                ->dailyAt('03:00');
        });
    }
}
