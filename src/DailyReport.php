<?php

namespace Nuvori\AomClient;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Process;

class DailyReport
{
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
        ];
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
