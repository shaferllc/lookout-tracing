<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * Wraps JSON exported from the **SPX** profiler (php-spx) for Lookout ingest.
 *
 * Generate SPX data in your environment, then POST the decoded JSON as {@code data}.
 */
final class SpxPayload
{
    /**
     * @param  array<string, mixed>  $spxReport  Decoded SPX JSON export.
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function toIngestPayload(array $spxReport, array $context = []): array
    {
        return array_filter(array_merge([
            'agent' => 'spx',
            'format' => 'spx.json',
            'data' => $spxReport,
        ], $context), fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
