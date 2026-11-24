<?php

namespace Nuvori\AomClient\Tests;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nuvori\AomClient\HealthCheck;
use RuntimeException;

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

    public function test_the_health_ping_is_sent_every_minute(): void
    {
        $this->fakeAom();
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'aom:send-health');

        $this->assertSame('* * * * *', $event->expression);

        $event->run($this->app);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://aom.nuvori.nl/api/v1/health'
            && $request->hasHeader('Authorization', 'Bearer aom_ins_secret'));
    }

    public function test_it_reports_pending_jobs_the_oldest_job_and_failed_jobs_per_queue(): void
    {
        $this->fakeAom();
        $this->createQueueTables();
        config(['horizon.defaults' => ['mails' => ['connection' => 'database', 'queue' => ['mails', 'default']]]]);
        config(['horizon.environments.testing' => ['exports' => ['queue' => 'exports']]]);
        $this->travelTo('2026-11-23 09:00:00');
        $this->pushJob('mails', now()->subMinutes(42));
        $this->pushJob('mails', now()->subMinutes(3));
        $this->pushJob('mails', now()->subHour(), reserved: true);
        $this->pushJob('exports', now()->addMinutes(5));
        DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'mails', 'payload' => '{}', 'exception' => 'boom']);

        app(HealthCheck::class)->send();

        Http::assertSent(fn (Request $request) => $request->data() === [
            'queues' => [
                ['name' => 'default', 'pending' => 0, 'oldest_pending_seconds' => null],
                ['name' => 'mails', 'pending' => 3, 'oldest_pending_seconds' => 2520],
                ['name' => 'exports', 'pending' => 1, 'oldest_pending_seconds' => 0],
            ],
            'failed_jobs' => 1,
        ]);
    }

    public function test_a_redis_queue_reports_the_age_of_the_job_at_the_head_of_the_list(): void
    {
        $this->fakeAom();
        config(['queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis', 'queue' => 'default']]);
        $this->travelTo('2026-11-23 09:00:00');
        Queue::shouldReceive('connection')->with('redis')->andReturn(new class
        {
            public function size(string $queue): int
            {
                return 7;
            }

            public function getQueue(string $queue): string
            {
                return "queues:{$queue}";
            }

            public function getConnection(): object
            {
                return new class
                {
                    public function lindex(string $key, int $index): string
                    {
                        return json_encode(['pushedAt' => (string) (now()->subMinutes(20)->getTimestamp() + 0.25)]);
                    }
                };
            }
        });

        $this->assertSame(
            [['name' => 'default', 'pending' => 7, 'oldest_pending_seconds' => 1200]],
            app(HealthCheck::class)->report()['queues'],
        );
    }

    public function test_a_sync_queue_reports_no_queues(): void
    {
        $this->fakeAom();
        config(['queue.default' => 'sync', 'queue.failed.driver' => 'null']);

        app(HealthCheck::class)->send();

        Http::assertSent(fn (Request $request) => $request->data() === ['queues' => [], 'failed_jobs' => 0]);
    }

    public function test_a_broken_queue_still_sends_the_ping(): void
    {
        $this->fakeAom();

        app(HealthCheck::class)->send();

        Http::assertSent(fn (Request $request) => $request->data() === ['queues' => [], 'failed_jobs' => null]);
    }

    public function test_a_failing_ping_never_breaks_the_scheduler(): void
    {
        config(['aom.install_token' => 'aom_ins_secret']);
        Http::fake(fn () => throw new RuntimeException('AOM is down'));

        app(HealthCheck::class)->send();

        $this->addToAssertionCount(1);
    }

    public function test_without_config_no_ping_is_sent(): void
    {
        Http::fake();

        app(HealthCheck::class)->send();

        Http::assertNothingSent();
    }

    private function fakeAom(): void
    {
        config(['aom.install_token' => 'aom_ins_secret', 'queue.failed.database' => 'testing']);
        Http::fake(['*' => Http::response([], 202)]);
    }

    private function createQueueTables(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function pushJob(string $queue, DateTimeInterface $availableAt, bool $reserved = false): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reserved ? now()->getTimestamp() : null,
            'available_at' => $availableAt->getTimestamp(),
            'created_at' => $availableAt->getTimestamp(),
        ]);
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
