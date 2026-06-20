<?php

declare(strict_types=1);

namespace Lookout\Tracing\Job;

use Lookout\Tracing\HttpTransport;

/**
 * PHP client for {@code POST /api/ingest/job} queue job run reporting.
 */
final class Client
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     job_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null
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
     * Send one job run event. Returns {@code run_id} from the API response, or null on failure.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  array{class?: string, message?: string, stack?: string}|null  $exception
     */
    public static function captureRun(
        string $jobName,
        string $status,
        ?string $runId = null,
        ?float $durationSeconds = null,
        ?string $queue = null,
        ?string $connection = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $traceId = null,
        ?int $attempt = null,
        ?array $meta = null,
        ?array $exception = null,
    ): ?string {
        $body = array_filter([
            'job' => $jobName,
            'status' => $status,
            'run_id' => $runId,
            'duration' => $durationSeconds,
            'queue' => $queue,
            'connection' => $connection,
            'environment' => $environment ?? (self::$config['environment'] ?? null),
            'release' => $release ?? (self::$config['release'] ?? null),
            'trace_id' => $traceId,
            'attempt' => $attempt,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
            'exception' => $exception !== null && $exception !== [] ? $exception : null,
        ], fn (mixed $v): bool => $v !== null);

        $url = self::jobUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return null;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body);
        if (! $res['ok'] || ! is_array($res['data'])) {
            return null;
        }

        return isset($res['data']['run_id']) && is_string($res['data']['run_id'])
            ? $res['data']['run_id']
            : null;
    }

    private static function jobUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['job_ingest_path'] ?? '/api/ingest/job');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
