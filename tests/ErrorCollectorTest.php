<?php

namespace Nuvori\AomClient\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Nuvori\AomClient\ErrorCollector;
use RuntimeException;

class ErrorCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['aom.url' => 'https://aom.test', 'aom.install_token' => 'aom_ins_secret', 'aom.app_version' => '2.4.1']);
        Http::fake(['*' => Http::response(['accepted' => 1], 202)]);
        $this->travelTo(now()->startOfMinute());
    }

    public function test_100_identical_errors_in_a_minute_become_one_request(): void
    {
        foreach (range(1, 100) as $ignored) {
            report($this->exception());
        }

        $this->travel(1)->minutes();
        app(ErrorCollector::class)->flush();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://aom.test/api/v1/errors'
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
                && array_keys($error) === ['fingerprint', 'class', 'message', 'file', 'line', 'path', 'count', 'first_at', 'last_at']
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
        config(['aom.url' => null]);

        report($this->exception());
        $this->travel(1)->minutes();
        config(['aom.url' => 'https://aom.test']);
        app(ErrorCollector::class)->flush();

        Http::assertNothingSent();
    }

    public function test_errors_are_sent_every_minute_by_the_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'aom:send-errors');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    private function exception(): RuntimeException
    {
        return new RuntimeException('Something broke');
    }
}
