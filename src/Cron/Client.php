<?php

declare(strict_types=1);

namespace Lookout\Tracing\Cron;

use Lookout\Tracing\HttpTransport;

/**
 * PHP client for {@code POST /api/ingest/cron} (Sentry Crons–style check-ins).
 *
 * @see https://docs.sentry.io/platforms/php/crons/
 */
final class Client
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     cron_ingest_path?: string|null
     * }  $config
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function resetForTesting(): void
    {
        self::$config = [];
    }

    /**
     * Send one check-in. Returns {@code check_in_id} from the API response, or null on failure.
     *
     * @param  array<string, mixed>|null  $meta  Optional key/value context (strings, numbers, bools); truncated server-side.
     */
    public static function captureCheckIn(
        string $slug,
        string $status,
        ?string $checkInId = null,
        ?float $durationSeconds = null,
        ?MonitorConfig $monitorConfig = null,
        ?string $environment = null,
        ?array $meta = null,
    ): ?string {
        $body = array_filter([
            'slug' => $slug,
            'status' => $status,
            'check_in_id' => $checkInId,
            'duration' => $durationSeconds,
            'monitor_config' => $monitorConfig !== null ? $monitorConfig->toArray() : null,
            'environment' => $environment,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
        ], fn (mixed $v): bool => $v !== null);

        $url = self::cronUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return null;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body);
        if (! $res['ok'] || ! is_array($res['data'])) {
            return null;
        }

        return isset($res['data']['check_in_id']) && is_string($res['data']['check_in_id'])
            ? $res['data']['check_in_id']
            : null;
    }

    /**
     * Runs {@code in_progress} then {@code ok} or {@code error} with elapsed duration (seconds).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withMonitor(string $slug, callable $callback, ?MonitorConfig $monitorConfig = null): mixed
    {
        $id = self::captureCheckIn($slug, CheckInStatus::inProgress(), null, null, $monitorConfig, null, null);
        $start = hrtime(true);
        try {
            $result = $callback();
            $dur = (hrtime(true) - $start) / 1e9;
            if ($id !== null) {
                self::captureCheckIn($slug, CheckInStatus::ok(), $id, $dur, null, null, null);
            }

            return $result;
        } catch (\Throwable $e) {
            $dur = (hrtime(true) - $start) / 1e9;
            if ($id !== null) {
                self::captureCheckIn($slug, CheckInStatus::error(), $id, $dur, null, null, null);
            }
            throw $e;
        }
    }

    private static function cronUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['cron_ingest_path'] ?? '/api/ingest/cron');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
