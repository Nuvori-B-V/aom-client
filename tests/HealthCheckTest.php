<?php

namespace Nuvori\AomClient\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

class HealthCheckTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
    }

    public function test_a_healthy_app_returns_200(): void
    {
        $this->beatQueueAndScheduler();

        $this->getJson('/aom/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => true, 'cache' => true, 'queue' => true, 'scheduler' => true, 'disk' => true],
            ]);
    }

    public function test_a_stopped_queue_worker_makes_the_health_check_fail(): void
    {
        $this->beatQueueAndScheduler();
        $this->travel(4)->minutes();
        $this->runScheduler();

        $this->getJson('/aom/health')
            ->assertStatus(503)
            ->assertJson(['status' => 'failing', 'checks' => ['queue' => false, 'scheduler' => true]]);
    }

    public function test_a_stopped_scheduler_makes_the_health_check_fail(): void
    {
        $this->beatQueueAndScheduler();
        $this->travel(4)->minutes();
        Event::dispatch(new Looping('database', 'default'));

        $this->getJson('/aom/health')->assertStatus(503)->assertJson(['checks' => ['queue' => true, 'scheduler' => false]]);
    }

    public function test_a_sync_queue_needs_no_worker(): void
    {
        config(['queue.default' => 'sync']);
        $this->runScheduler();

        $this->getJson('/aom/health')->assertOk()->assertJson(['checks' => ['queue' => true]]);
    }

    public function test_a_broken_database_fails_without_leaking_details(): void
    {
        $this->beatQueueAndScheduler();
        config(['database.connections.testing.database' => '/nonexistent/dir/db.sqlite']);
        app('db')->purge('testing');

        $response = $this->getJson('/aom/health')->assertStatus(503)->assertJson(['checks' => ['database' => false]]);

        $this->assertStringNotContainsString('nonexistent', $response->getContent());
    }

    private function beatQueueAndScheduler(): void
    {
        Event::dispatch(new Looping('database', 'default'));
        $this->runScheduler();
    }

    private function runScheduler(): void
    {
        collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'aom:scheduler-heartbeat')
            ->run($this->app);
    }
}
