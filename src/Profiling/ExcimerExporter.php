<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * Converts an {@see \ExcimerLog} instance to Lookout profile ingest JSON.
 *
 * Requires the Excimer PECL extension (same stack Sentry PHP profiling uses).
 *
 * @see https://docs.sentry.io/platforms/php/profiling/
 * @see https://www.mediawiki.org/wiki/Excimer
 */
final class ExcimerExporter
{
    public static function isAvailable(): bool
    {
        return extension_loaded('excimer');
    }

    /**
     * @param  object  $log  {@see \ExcimerLog} from {@see \ExcimerProfiler::flush()} or similar.
     * @param  array<string, mixed>  $context  Optional trace_id, transaction, environment, release for ingest.
     * @return array<string, mixed>
     */
    public static function toIngestPayload(object $log, array $context = []): array
    {
        if (! method_exists($log, 'getSpeedscopeData')) {
            throw new \InvalidArgumentException('Expected ExcimerLog with getSpeedscopeData().');
        }

        $speedscope = $log->getSpeedscopeData();
        if (! is_array($speedscope)) {
            $speedscope = [];
        }

        return array_filter(array_merge([
            'agent' => 'excimer',
            'format' => 'speedscope',
            'data' => $speedscope,
        ], $context), fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
