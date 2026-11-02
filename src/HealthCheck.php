<?php

namespace Nuvori\AomClient;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class HealthCheck
{
    public const QUEUE_HEARTBEAT = 'aom:queue-heartbeat';

    public const SCHEDULER_HEARTBEAT = 'aom:scheduler-heartbeat';

    private const HEARTBEAT_SECONDS = 180;

    private const MIN_FREE_DISK = 0.05;

    public function response(): JsonResponse
    {
        $checks = $this->checks();
        $healthy = ! in_array(false, $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'failing', 'checks' => $checks], $healthy ? 200 : 503);
    }

    /**
     * @return array<string, bool>
     */
    public function checks(): array
    {
        return [
            'database' => $this->attempt(fn () => DB::connection()->getPdo() !== null),
            'cache' => $this->attempt(function () {
                $value = Str::random();
                Cache::put('aom:health', $value, 10);

                return Cache::get('aom:health') === $value;
            }),
            'queue' => config('queue.default') === 'sync' || $this->isRecent(self::QUEUE_HEARTBEAT),
            'scheduler' => $this->isRecent(self::SCHEDULER_HEARTBEAT),
            'disk' => $this->attempt(fn () => disk_free_space(base_path()) / disk_total_space(base_path()) >= self::MIN_FREE_DISK),
        ];
    }

    public function beat(string $key): void
    {
        if (! $this->isRecent($key, 30)) {
            Cache::put($key, now()->getTimestamp(), self::HEARTBEAT_SECONDS * 2);
        }
    }

    private function isRecent(string $key, int $seconds = self::HEARTBEAT_SECONDS): bool
    {
        return $this->attempt(fn () => now()->getTimestamp() - (int) Cache::get($key, 0) <= $seconds);
    }

    private function attempt(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable) {
            return false;
        }
    }
}
