<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Events\BatchCanceled;
use Illuminate\Bus\Events\BatchDispatched;
use Illuminate\Bus\Events\BatchFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Lookout\Tracing\Batch\Client as BatchClient;
use Lookout\Tracing\Tracer;

/**
 * Reports queue batch lifecycle to {@code POST /api/ingest/batch} (Telescope Batch Watcher equivalent).
 *
 * Each batch is upserted server-side by (project_id, batch_id), so the same batch transitions in place
 * from running → finished/failed/cancelled as the relevant Bus events fire.
 */
final class BatchMonitoringInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        if (class_exists(BatchDispatched::class)) {
            $events->listen(BatchDispatched::class, [self::class, 'onBatchDispatched']);
        }
        if (class_exists(BatchFinished::class)) {
            $events->listen(BatchFinished::class, [self::class, 'onBatchFinished']);
        }
        if (class_exists(BatchCanceled::class)) {
            $events->listen(BatchCanceled::class, [self::class, 'onBatchCanceled']);
        }
    }

    public static function onBatchDispatched(BatchDispatched $event): void
    {
        self::capture($event->batch, 'running');
    }

    public static function onBatchFinished(BatchFinished $event): void
    {
        $batch = $event->batch;
        $status = ($batch->failedJobs ?? 0) > 0 ? 'failed' : 'finished';
        self::capture($batch, $status);
    }

    public static function onBatchCanceled(BatchCanceled $event): void
    {
        self::capture($event->batch, 'cancelled');
    }

    private static function capture(Batch $batch, string $status): void
    {
        if (! self::enabled()) {
            return;
        }

        $name = is_string($batch->name) && trim($batch->name) !== '' ? $batch->name : null;

        BatchClient::captureBatch(
            (string) $batch->id,
            $name,
            (int) ($batch->totalJobs ?? 0),
            (int) ($batch->pendingJobs ?? 0),
            (int) ($batch->failedJobs ?? 0),
            $status,
            self::formatTime($batch->createdAt ?? null),
            self::formatTime($batch->finishedAt ?? null),
            self::formatTime($batch->cancelledAt ?? null),
            null,
            null,
            self::currentTraceId(),
        );
    }

    private static function formatTime(mixed $time): ?string
    {
        if ($time instanceof DateTimeInterface) {
            return $time->format(DateTimeInterface::ATOM);
        }

        return null;
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $batchCfg = is_array($cfg['batch_monitoring'] ?? null) ? $cfg['batch_monitoring'] : [];
        if (empty($batchCfg['enabled'])) {
            return false;
        }

        $key = $cfg['api_key'] ?? null;
        $base = $cfg['base_uri'] ?? null;

        return is_string($key) && $key !== '' && is_string($base) && rtrim(trim($base), '/') !== '';
    }

    private static function currentTraceId(): ?string
    {
        $id = Tracer::instance()->traceId();

        return $id !== '' ? $id : null;
    }
}
