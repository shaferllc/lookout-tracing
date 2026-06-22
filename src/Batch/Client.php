<?php

declare(strict_types=1);

namespace Lookout\Tracing\Batch;

use Lookout\Tracing\HttpTransport;

/**
 * PHP client for {@code POST /api/ingest/batch} queue-batch monitoring (Telescope Batch Watcher equivalent).
 */
final class Client
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     batch_ingest_path?: string|null,
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
     * @param  array<string, mixed>|null  $meta
     */
    public static function captureBatch(
        string $batchId,
        ?string $name,
        int $totalJobs,
        int $pendingJobs,
        int $failedJobs,
        string $status,
        ?string $dispatchedAt = null,
        ?string $finishedAt = null,
        ?string $cancelledAt = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $traceId = null,
        ?array $meta = null,
    ): bool {
        $body = array_filter([
            'batch_id' => $batchId,
            'name' => $name,
            'total_jobs' => $totalJobs,
            'pending_jobs' => $pendingJobs,
            'processed_jobs' => max(0, $totalJobs - $pendingJobs),
            'failed_jobs' => $failedJobs,
            'status' => $status,
            'dispatched_at' => $dispatchedAt,
            'finished_at' => $finishedAt,
            'cancelled_at' => $cancelledAt,
            'environment' => $environment ?? (self::$config['environment'] ?? null),
            'release' => $release ?? (self::$config['release'] ?? null),
            'trace_id' => $traceId,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
        ], fn (mixed $v): bool => $v !== null);

        $url = self::batchUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return false;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body);

        return (bool) ($res['ok'] ?? false);
    }

    private static function batchUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['batch_ingest_path'] ?? '/api/ingest/batch');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
