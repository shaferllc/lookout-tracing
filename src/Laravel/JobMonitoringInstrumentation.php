<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Lookout\Tracing\Job\Client as JobClient;
use Lookout\Tracing\Job\RunStatus;
use Lookout\Tracing\Tracer;
use Throwable;

/**
 * Reports queue job lifecycle to {@code POST /api/ingest/job} for production job monitoring.
 */
final class JobMonitoringInstrumentation
{
    /** @var array<string, array{run_id: string, started: int}> */
    private static array $activeRuns = [];

    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        $events->listen(JobProcessing::class, [self::class, 'onJobProcessing']);
        $events->listen(JobAttempted::class, [self::class, 'onJobAttempted']);
    }

    public static function onJobProcessing(JobProcessing $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $key = self::jobKey($event->job);
        $queueName = '';
        if ($event->job instanceof QueueJobContract && method_exists($event->job, 'getQueue')) {
            $queueName = (string) $event->job->getQueue();
        }
        $attempt = 1;
        if ($event->job instanceof QueueJobContract && method_exists($event->job, 'attempts')) {
            $attempt = (int) $event->job->attempts();
        }

        $runId = JobClient::captureRun(
            self::resolveJobName($event->job),
            RunStatus::inProgress(),
            null,
            null,
            $queueName !== '' ? $queueName : null,
            $event->connectionName,
            null,
            null,
            self::currentTraceId(),
            $attempt,
        );

        if ($runId !== null && $key !== null) {
            self::$activeRuns[$key] = [
                'run_id' => $runId,
                'started' => hrtime(true),
            ];
        }
    }

    public static function onJobAttempted(JobAttempted $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $key = self::jobKey($event->job);
        if ($key === null || ! isset(self::$activeRuns[$key])) {
            return;
        }

        $active = self::$activeRuns[$key];
        unset(self::$activeRuns[$key]);

        $duration = (hrtime(true) - $active['started']) / 1e9;
        $successful = $event->successful();
        $exception = null;
        if (! $successful) {
            $ex = $event->exception;
            if ($ex instanceof Throwable) {
                $msg = $ex->getMessage();
                if (strlen($msg) > 500) {
                    $msg = substr($msg, 0, 497).'…';
                }
                $trace = $ex->getTraceAsString();
                if (strlen($trace) > 20000) {
                    $trace = substr($trace, 0, 19997).'…';
                }
                $exception = [
                    'class' => $ex::class,
                    'message' => $msg,
                    'stack' => $trace,
                ];
            }
        }

        JobClient::captureRun(
            self::resolveJobName($event->job),
            $successful ? RunStatus::ok() : RunStatus::error(),
            $active['run_id'],
            $duration,
            null,
            null,
            null,
            null,
            self::currentTraceId(),
            null,
            null,
            $exception,
        );
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $jobCfg = is_array($cfg['job_monitoring'] ?? null) ? $cfg['job_monitoring'] : [];
        if (empty($jobCfg['enabled'])) {
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

    private static function jobKey(mixed $job): ?string
    {
        if ($job instanceof QueueJobContract && method_exists($job, 'uuid')) {
            $uuid = $job->uuid();

            return is_string($uuid) && $uuid !== '' ? $uuid : null;
        }

        return null;
    }

    private static function resolveJobName(mixed $job): string
    {
        if ($job instanceof QueueJobContract && method_exists($job, 'resolveName')) {
            return (string) $job->resolveName();
        }

        return 'queue.job';
    }
}
