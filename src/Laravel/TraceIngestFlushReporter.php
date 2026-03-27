<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Lookout\Tracing\Tracer;

/**
 * Runs {@see Tracer::flushWithResult()} and logs when Lookout refuses trace ingest (HTTP 403).
 */
final class TraceIngestFlushReporter
{
    /**
     * @return array{
     *     ok: bool,
     *     skipped: bool,
     *     status: int|null,
     *     response: array<string, mixed>|null,
     * }
     */
    public static function flushWithReporting(): array
    {
        $r = Tracer::instance()->flushWithResult();
        self::logForbiddenIfNeeded($r);

        return $r;
    }

    /**
     * @param  array{
     *     ok: bool,
     *     skipped: bool,
     *     status: int|null,
     *     response: array<string, mixed>|null,
     * }  $r
     */
    public static function logForbiddenIfNeeded(array $r): void
    {
        if ($r['skipped'] || $r['status'] !== 403) {
            return;
        }

        $perf = config('lookout-tracing.performance');
        $log = is_array($perf) && ($perf['log_forbidden_trace_ingest'] ?? true);
        if (! $log) {
            return;
        }

        if (! function_exists('logger')) {
            return;
        }

        $msg = is_array($r['response']) && isset($r['response']['message']) && is_string($r['response']['message'])
            ? $r['response']['message']
            : 'Trace ingest returned HTTP 403 (performance ingest disabled for this project or invalid key).';

        logger()->warning('lookout.tracing.trace_forbidden', [
            'message' => $msg,
            'status' => 403,
        ]);
    }
}
