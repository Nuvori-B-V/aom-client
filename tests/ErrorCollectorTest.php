<?php

namespace Nuvori\AomClient\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Nuvori\AomClient\ErrorCollector;
use RuntimeException;

class ErrorCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['aom.install_token' => 'aom_ins_secret', 'aom.app_version' => '2.4.1']);
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        $this->travelTo(now()->startOfMinute());
        $this->app->useStoragePath(sys_get_temp_dir().'/aom-client-'.uniqid());
        File::ensureDirectoryExists(storage_path('logs'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    public function test_100_identical_errors_in_a_minute_become_one_request(): void
    {
        foreach (range(1, 100) as $ignored) {
            report($this->exception());
        }

        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://aom.nuvori.nl/api/v1/errors'
            && $request['app_version'] === '2.4.1'
            && count($request['errors']) === 1
            && $request['errors'][0]['count'] === 100
            && $request['errors'][0]['class'] === RuntimeException::class
            && str_ends_with($request['errors'][0]['file'], 'tests/ErrorCollectorTest.php')
            && str_starts_with($request['errors'][0]['fingerprint'], 'sha256:'));
    }

    public function test_errors_of_the_current_minute_wait_for_the_next_flush(): void
    {
        report($this->exception());

        app(ErrorCollector::class)->flush();
        Http::assertNothingSent();

        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();
        app(ErrorCollector::class)->flush();
        Http::assertSentCount(1);
    }

    public function test_different_errors_get_different_fingerprints(): void
    {
        report(new RuntimeException('one'));
        report(new RuntimeException('two'));

        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSent(fn (Request $request) => count($request['errors']) === 2);
    }

    public function test_sensitive_data_never_leaves_the_app(): void
    {
        Route::post('/projects/{project}', fn () => throw new RuntimeException(
            'Login failed for jane@example.com with password=hunter2 and token: "abc123" (Connection: pgsql, SQL: select * from users where email = jane@example.com)'
        ));

        $this->withCookie('laravel_session', 'cookie-value')
            ->withHeader('X-Secret-Header', 'header-value')
            ->post('/projects/12', ['password' => 'body-password', 'name' => 'Jane']);

        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $error = $request['errors'][0];

            return $error['path'] === '/projects/{project}'
                && $error['message'] === 'Login failed for [email] with password=[filtered] and token: [filtered]'
                && array_keys($error) === ['fingerprint', 'class', 'message', 'file', 'line', 'path', 'trace', 'log', 'count', 'first_at', 'last_at']
                && ! str_contains($body, 'jane@example.com')
                && ! str_contains($body, 'hunter2')
                && ! str_contains($body, 'abc123')
                && ! str_contains($body, 'body-password')
                && ! str_contains($body, 'cookie-value')
                && ! str_contains($body, 'header-value')
                && ! str_contains($body, 'select * from users');
        });
    }

    public function test_without_config_nothing_is_collected_or_sent(): void
    {
        config(['aom.install_token' => null]);

        report($this->exception());
        $this->travel(1)->minutes();
        config(['aom.install_token' => 'aom_ins_secret']);
        app(ErrorCollector::class)->flush();

        Http::assertNothingSent();
    }

    public function test_errors_are_sent_every_minute_by_the_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'aom:send-errors');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_an_error_carries_a_stack_trace_without_arguments(): void
    {
        report($this->exception());

        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSent(function (Request $request) {
            $trace = $request['errors'][0]['trace'];

            return count($trace) > 0
                && count($trace) <= 30
                && $trace[0]['function'] === 'exception'
                && $trace[0]['class'] === self::class
                && collect($trace)->every(fn (array $frame) => array_keys($frame) === ['file', 'line', 'class', 'function', 'vendor'])
                && collect($trace)->contains(fn (array $frame) => $frame['vendor'] === true);
        });
    }

    public function test_an_error_carries_the_last_redacted_lines_of_the_newest_log(): void
    {
        File::put(storage_path('logs/laravel-2026-11-10.log'), "[2026-11-10 23:59:00] production.INFO: Old day\n");
        touch(storage_path('logs/laravel-2026-11-10.log'), time() - 3600);
        File::put(storage_path('logs/laravel-2026-11-11.log'), collect(range(1, 60))->map(fn (int $line) => "[2026-11-11 09:00:00] production.INFO: Line {$line}")->implode("\n")."\n[2026-11-11 09:00:01] production.INFO: Login jane@example.com password=hunter2\n");

        report($this->exception());
        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSent(function (Request $request) {
            $lines = explode("\n", $request['errors'][0]['log']);

            return count($lines) === 50
                && $lines[0] === '[2026-11-11 09:00:00] production.INFO: Line 12'
                && $lines[49] === '[2026-11-11 09:00:01] production.INFO: Login [email] password=[filtered]'
                && ! str_contains($request->body(), 'Old day');
        });
    }

    public function test_the_log_is_capped_at_20_kb_without_a_partial_first_line(): void
    {
        File::put(storage_path('logs/laravel.log'), str_repeat(str_repeat('a', 999)."\n", 30));

        report($this->exception());
        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSent(fn (Request $request) => $request['errors'][0]['log'] === trim(str_repeat(str_repeat('a', 999)."\n", 19)));
    }

    public function test_without_a_log_file_the_error_is_sent_without_log(): void
    {
        report($this->exception());
        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSent(fn (Request $request) => $request['errors'][0]['log'] === null);
    }

    private function exception(): RuntimeException
    {
        return new RuntimeException('Something broke');
    }
}
