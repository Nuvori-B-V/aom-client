<?php

namespace Nuvori\AomClient;

use Illuminate\Http\JsonResponse;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    public function isRecent(string $key, int $seconds = self::HEARTBEAT_SECONDS): bool
    {
        return $this->attempt(fn () => now()->getTimestamp() - (int) Cache::get($key, 0) <= $seconds);
    }

    public function send(): void
    {
        try {
            app(Aom::class)->post('health', $this->report());
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return array{queues: list<array{name: string, pending: int, oldest_pending_seconds: ?int}>, failed_jobs: ?int}
     */
    public function report(): array
    {
        $queues = [];

        foreach ($this->queues() as $name => $connection) {
            try {
                $queues[] = [
                    'name' => $name,
                    'pending' => (int) Queue::connection($connection)->size($name),
                    'oldest_pending_seconds' => $this->oldestPendingSeconds($connection, $name),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return ['queues' => $queues, 'failed_jobs' => $this->failedJobs()];
    }

    /**
     * @return array<string, string>
     */
    private function queues(): array
    {
        $default = (string) config('queue.default');
        $queues = [];

        if (config("queue.connections.{$default}.driver") !== 'sync') {
            $queues[(string) config("queue.connections.{$default}.queue", 'default')] = $default;
        }

        $supervisors = [
            ...array_values((array) config('horizon.defaults', [])),
            ...array_values((array) config('horizon.environments.'.app()->environment(), [])),
        ];

        foreach ($supervisors as $supervisor) {
            $names = $supervisor['queue'] ?? [];

            foreach (is_array($names) ? $names : explode(',', (string) $names) as $name) {
                $queues[trim((string) $name)] ??= (string) ($supervisor['connection'] ?? $default);
            }
        }

        unset($queues['']);

        return $queues;
    }

    private function oldestPendingSeconds(string $connection, string $name): ?int
    {
        try {
            $config = (array) config("queue.connections.{$connection}");
            $pushedAt = match ($config['driver'] ?? null) {
                'database' => DB::connection($config['connection'] ?? null)
                    ->table($config['table'] ?? 'jobs')
                    ->where('queue', $name)
                    ->whereNull('reserved_at')
                    ->min('available_at'),
                'redis' => $this->redisPushedAt(Queue::connection($connection), $name),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return $pushedAt === null ? null : max(0, now()->getTimestamp() - (int) $pushedAt);
    }

    private function redisPushedAt(object $queue, string $name): ?int
    {
        $payload = json_decode((string) $queue->getConnection()->lindex($queue->getQueue($name), 0), true);
        $pushedAt = $payload['pushedAt'] ?? $payload['createdAt'] ?? null;

        return $pushedAt === null ? null : (int) $pushedAt;
    }

    private function failedJobs(): ?int
    {
        try {
            $failer = app('queue.failer');

            return $failer instanceof CountableFailedJobProvider ? (int) $failer->count() : null;
        } catch (Throwable) {
            return null;
        }
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
