<?php

namespace Nuvori\AomClient;

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
            $file = $this->relativePath($exception->getFile());
            $fingerprint = 'sha256:'.hash('sha256', get_class($exception).'|'.$file.'|'.$exception->getLine());
            $minute = now()->startOfMinute()->getTimestamp();
            $at = now()->utc()->toIso8601ZuluString();

            Cache::lock('aom:errors:lock', 5)->block(2, function () use ($exception, $file, $fingerprint, $minute, $at) {
                $errors = Cache::get("aom:errors:{$minute}", []);

                $errors[$fingerprint] ??= [
                    'fingerprint' => $fingerprint,
                    'class' => get_class($exception),
                    'message' => $this->redact($exception->getMessage()),
                    'file' => $file,
                    'line' => $exception->getLine(),
                    'path' => $this->routePath(),
                    'trace' => $this->trace($exception),
                    'log' => rescue(fn () => $this->log(), null, false),
                    'count' => 0,
                    'first_at' => $at,
                ];
                $errors[$fingerprint]['count']++;
                $errors[$fingerprint]['last_at'] = $at;

                Cache::put("aom:errors:{$minute}", $errors, now()->addDay());
                Cache::put(self::MINUTES, array_unique([...Cache::get(self::MINUTES, []), $minute]), now()->addDay());
            });
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

    private function relativePath(string $file): string
    {
        return Str::startsWith($file, base_path().'/') ? Str::after($file, base_path().'/') : Str::after($file, '/vendor/');
    }

    /**
     * @return list<array{file: ?string, line: ?int, class: ?string, function: string, vendor: bool}>
     */
    private function trace(Throwable $exception): array
    {
        return array_map(fn (array $frame): array => [
            'file' => isset($frame['file']) ? $this->relativePath($frame['file']) : null,
            'line' => $frame['line'] ?? null,
            'class' => $frame['class'] ?? null,
            'function' => $frame['function'],
            'vendor' => isset($frame['file']) && str_contains($frame['file'], '/vendor/'),
        ], array_slice($exception->getTrace(), 0, 30));
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
