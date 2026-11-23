<?php

namespace Spatie\Backup\BackupDestination;

class BackupDestination
{
    public function __construct(private float $size) {}

    public static function create(string $diskName, string $backupName): self
    {
        return new self(2048.0);
    }

    public function newestBackup(): object
    {
        return new class($this->size)
        {
            public function __construct(private float $size) {}

            public function sizeInBytes(): float
            {
                return $this->size;
            }
        };
    }
}

namespace Spatie\Backup\Events;

use Exception;
use Spatie\Backup\BackupDestination\BackupDestination;

class BackupWasSuccessful
{
    public function __construct(
        public ?BackupDestination $backupDestination = null,
        public ?string $diskName = null,
        public ?string $backupName = null,
    ) {}
}

class BackupHasFailed
{
    public function __construct(
        public Exception $exception,
        public ?BackupDestination $backupDestination = null,
    ) {}
}

namespace Nuvori\AomClient\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Nuvori\AomClient\DailyReport;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

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

    public function test_without_a_backup_run_the_backup_is_unknown(): void
    {
        Process::fake();

        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => array_key_exists('backup', $request->data()) && $request['backup'] === null);
    }

    public function test_a_successful_backup_is_reported_with_its_time_and_size(): void
    {
        Process::fake();
        $this->travelTo('2026-11-20 02:00:00');

        Event::dispatch(new BackupWasSuccessful(new BackupDestination(1288490188.0)));
        $this->travelTo('2026-11-20 03:00:00');
        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request['backup'] === [
            'last_attempt_at' => '2026-11-20T02:00:00+00:00',
            'last_attempt_ok' => true,
            'last_success_at' => '2026-11-20T02:00:00+00:00',
            'last_success_size' => 1288490188,
        ]);
    }

    public function test_a_backup_event_that_only_names_its_disk_is_reported_with_its_size(): void
    {
        Process::fake();

        Event::dispatch(new BackupWasSuccessful(diskName: 'backups', backupName: 'app'));
        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request['backup']['last_attempt_ok'] === true
            && $request['backup']['last_success_size'] === 2048);
    }

    public function test_a_failed_backup_keeps_the_last_good_backup(): void
    {
        Process::fake();
        $this->travelTo('2026-11-20 02:00:00');
        Event::dispatch(new BackupWasSuccessful(new BackupDestination(4096.0)));
        $this->travelTo('2026-11-21 02:00:00');

        Event::dispatch(new BackupHasFailed(new RuntimeException('disk full')));
        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request['backup'] === [
            'last_attempt_at' => '2026-11-21T02:00:00+00:00',
            'last_attempt_ok' => false,
            'last_success_at' => '2026-11-20T02:00:00+00:00',
            'last_success_size' => 4096,
        ]);
    }

    public function test_a_backup_that_never_succeeded_reports_no_good_backup(): void
    {
        Process::fake();
        $this->travelTo('2026-11-21 02:00:00');

        Event::dispatch(new BackupHasFailed(new RuntimeException('disk full')));
        app(DailyReport::class)->send();

        Http::assertSent(fn (Request $request) => $request['backup'] === [
            'last_attempt_at' => '2026-11-21T02:00:00+00:00',
            'last_attempt_ok' => false,
            'last_success_at' => null,
            'last_success_size' => null,
        ]);
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
