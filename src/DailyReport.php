<?php

namespace Nuvori\AomClient;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

class DailyReport
{
    public const BACKUP = 'aom:backup';

    public function send(): void
    {
        if (! app(Aom::class)->isConfigured()) {
            return;
        }

        app(Aom::class)->post('report', $this->build());
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'app_version' => config('aom.app_version'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'packages' => $this->packages(),
            'advisories' => $this->advisories(),
            'backup' => $this->backup(),
        ];
    }

    public function recordBackup(bool $ok, object $event): void
    {
        try {
            $now = now()->toIso8601String();
            $previous = $this->backup();

            Cache::forever(self::BACKUP, [
                'last_attempt_at' => $now,
                'last_attempt_ok' => $ok,
                'last_success_at' => $ok ? $now : $previous['last_success_at'] ?? null,
                'last_success_size' => $ok ? $this->backupSize($event) : $previous['last_success_size'] ?? null,
            ]);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backup(): ?array
    {
        try {
            $backup = Cache::get(self::BACKUP);
        } catch (Throwable) {
            return null;
        }

        return is_array($backup) ? $backup : null;
    }

    private function backupSize(object $event): ?int
    {
        try {
            $destination = $event->backupDestination
                ?? ('Spatie\Backup\BackupDestination\BackupDestination')::create($event->diskName, $event->backupName);
            $size = $destination->newestBackup()?->sizeInBytes();
        } catch (Throwable) {
            return null;
        }

        return $size === null ? null : (int) $size;
    }

    /**
     * @return array<string, string>
     */
    private function packages(): array
    {
        $root = InstalledVersions::getRootPackage()['name'];

        return collect(InstalledVersions::getInstalledPackages())
            ->reject(fn (string $package) => $package === $root)
            ->mapWithKeys(fn (string $package) => [$package => InstalledVersions::getPrettyVersion($package)])
            ->filter()
            ->sortKeys()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function advisories(): ?array
    {
        $result = Process::path(base_path())->timeout(120)->run(['composer', 'audit', '--locked', '--format=json', '--no-interaction']);
        $audit = json_decode($result->output(), true);

        if (! is_array($audit) || ! array_key_exists('advisories', $audit)) {
            return null;
        }

        return collect($audit['advisories'])
            ->flatten(1)
            ->map(fn (array $advisory) => [
                'package' => $advisory['packageName'],
                'advisory_id' => $advisory['advisoryId'],
                'severity' => $advisory['severity'] ?? null,
                'title' => $advisory['title'] ?? null,
                'cve' => $advisory['cve'] ?? null,
                'link' => $advisory['link'] ?? null,
            ])
            ->values()
            ->all();
    }
}
