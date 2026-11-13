<?php

namespace Nuvori\AomClient;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ErrorCollector
{
    private const SENSITIVE = 'password|passwd|secret|token|api[_-]?key|authorization|cookie|session|email|iban|bsn';

    private const MINUTES = 'aom:errors:minutes';

    private const LOG_LINES = 50;

    private const LOG_BYTES = 20000;

    public function capture(Throwable $exception): void
    {
        if (! app(Aom::class)->isConfigured()) {
            return;
        }

        try {
            $this->record(get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTrace());
        } catch (Throwable) {
        }
    }

    public function captureLog(MessageLogged $event): void
    {
        if (! app(Aom::class)->isConfigured()
            || ! in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)
            || ($event->context['exception'] ?? null) instanceof Throwable) {
            return;
        }

        try {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $index = collect($frames)->keys()->last(fn (int $index) => Str::startsWith($frames[$index]['class'] ?? '', ['Illuminate\\Log\\', 'Illuminate\\Support\\Facades\\']));

            if ($index !== null) {
                $this->record("log.{$event->level}", $event->message, $frames[$index]['file'] ?? '', $frames[$index]['line'] ?? 0, array_slice($frames, $index));
            }
        } catch (Throwable) {
        }
    }

    public function flush(): void
    {
        if (! app(Aom::class)->isConfigured()) {
            return;
        }

        $current = now()->startOfMinute()->getTimestamp();
        $errors = [];

        Cache::lock('aom:errors:lock', 5)->block(2, function () use ($current, &$errors) {
            $minutes = Cache::get(self::MINUTES, []);

            foreach ($minutes as $minute) {
                if ($minute < $current) {
                    $errors = [...$errors, ...array_values(Cache::pull("aom:errors:{$minute}", []))];
                }
            }

            Cache::put(self::MINUTES, array_values(array_filter($minutes, fn (int $minute) => $minute >= $current)), now()->addDay());
        });

        if ($errors !== []) {
            app(Aom::class)->post('errors', ['app_version' => config('aom.app_version'), 'errors' => $errors]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     */
    private function record(string $class, string $message, string $file, int $line, array $frames): void
    {
        $file = $this->relativePath($file);
        $fingerprint = 'sha256:'.hash('sha256', $class.'|'.$file.'|'.$line);
        $minute = now()->startOfMinute()->getTimestamp();
        $at = now()->utc()->toIso8601ZuluString();
        $error = fn (): array => [
            'fingerprint' => $fingerprint,
            'class' => $class,
            'message' => $this->redact($message),
            'file' => $file,
            'line' => $line,
            'path' => $this->routePath(),
            'trace' => $this->trace($frames),
            'log' => rescue(fn () => $this->log(), null, false),
        ];
        $sendNow = fn () => rescue(fn () => app(Aom::class)->post('errors', [
            'app_version' => config('aom.app_version'),
            'errors' => [[...$error(), 'count' => 1, 'first_at' => $at, 'last_at' => $at]],
        ]), null, false);

        if (! app(HealthCheck::class)->isRecent(HealthCheck::SCHEDULER_HEARTBEAT)) {
            $sendNow();

            return;
        }

        try {
            Cache::lock('aom:errors:lock', 5)->block(2, function () use ($error, $fingerprint, $minute, $at) {
                $errors = Cache::get("aom:errors:{$minute}", []);

                $errors[$fingerprint] ??= [...$error(), 'count' => 0, 'first_at' => $at];
                $errors[$fingerprint]['count']++;
                $errors[$fingerprint]['last_at'] = $at;

                Cache::put("aom:errors:{$minute}", $errors, now()->addDay());
                Cache::put(self::MINUTES, array_unique([...Cache::get(self::MINUTES, []), $minute]), now()->addDay());
            });
        } catch (Throwable) {
            $sendNow();
        }
    }

    private function relativePath(string $file): string
    {
        return Str::startsWith($file, base_path().'/') ? Str::after($file, base_path().'/') : Str::after($file, '/vendor/');
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return list<array{file: ?string, line: ?int, class: ?string, function: string, vendor: bool}>
     */
    private function trace(array $frames): array
    {
        return array_map(fn (array $frame): array => [
            'file' => isset($frame['file']) ? $this->relativePath($frame['file']) : null,
            'line' => $frame['line'] ?? null,
            'class' => $frame['class'] ?? null,
            'function' => $frame['function'],
            'vendor' => isset($frame['file']) && str_contains($frame['file'], '/vendor/'),
        ], array_slice($frames, 0, 30));
    }

    private function log(): ?string
    {
        $file = collect(glob(storage_path('logs/*.log')) ?: [])->sortByDesc(fn (string $file) => filemtime($file))->first();

        if ($file === null) {
            return null;
        }

        $offset = max(0, filesize($file) - self::LOG_BYTES);
        $lines = explode("\n", trim(mb_scrub(file_get_contents($file, offset: $offset))));
        $lines = array_slice($offset > 0 ? array_slice($lines, 1) : $lines, -self::LOG_LINES);
        $log = implode("\n", array_map(fn (string $line) => $this->redact($line), $lines));

        return $log === '' ? null : mb_substr($log, -self::LOG_BYTES);
    }

    private function routePath(): ?string
    {
        $uri = request()->route()?->uri();

        return $uri === null ? null : '/'.ltrim($uri, '/');
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/\s*\(Connection: .*$/s', '', $message);
        $message = preg_replace('/[^\s@"\'(),;]+@[^\s@"\'(),;]+\.[a-z]{2,}/i', '[email]', $message);
        $message = preg_replace('/\b('.self::SENSITIVE.')(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2[filtered]', $message);

        return Str::limit($message, 2000);
    }
}
