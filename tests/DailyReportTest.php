<?php

namespace Nuvori\AomClient\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Nuvori\AomClient\DailyReport;

class DailyReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['aom.install_token' => 'aom_ins_secret', 'aom.app_version' => '2.4.1']);
        Http::fake(['*' => Http::response([], 202)]);
    }

    public function test_it_reports_versions_packages_and_composer_audit_advisories(): void
    {
        Process::fake(['*' => Process::result(json_encode([
            'advisories' => [
                'guzzlehttp/psr7' => [[
                    'advisoryId' => 'PKSA-1234',
                    'packageName' => 'guzzlehttp/psr7',
                    'affectedVersions' => '<2.4.5',
                    'title' => 'Improper header validation',
                    'cve' => 'CVE-2023-29197',
                    'link' => 'https://github.com/advisories/GHSA-wxmh-65f7-jcvw',
                    'severity' => 'high',
                ]],
            ],
            'abandoned' => [],
        ]), exitCode: 1)]);

        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://aom.nuvori.nl/api/v1/report'
            && $request['app_version'] === '2.4.1'
            && $request['php_version'] === PHP_VERSION
            && $request['laravel_version'] === app()->version()
            && isset($request['packages']['laravel/framework'])
            && $request['advisories'] === [[
                'package' => 'guzzlehttp/psr7',
                'advisory_id' => 'PKSA-1234',
                'severity' => 'high',
                'title' => 'Improper header validation',
                'cve' => 'CVE-2023-29197',
                'link' => 'https://github.com/advisories/GHSA-wxmh-65f7-jcvw',
            ]]);
        Process::assertRan(fn ($process) => $process->command === ['composer', 'audit', '--locked', '--format=json', '--no-interaction']);
    }

    public function test_no_advisories_is_an_empty_list(): void
    {
        Process::fake(['*' => Process::result('{"advisories":[],"abandoned":[]}')]);

        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request['advisories'] === []);
    }

    public function test_a_failing_audit_sends_null_instead_of_guessing(): void
    {
        Process::fake(['*' => Process::result('', 'composer: not found', 127)]);

        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request['advisories'] === null);
    }

    public function test_without_config_nothing_is_sent(): void
    {
        Process::fake();
        config(['aom.install_token' => null]);

        app(DailyReport::class)->send();

        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_the_report_is_sent_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'aom:send-report');

        $this->assertSame('0 3 * * *', $event->expression);
    }
}
