<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Resets PHP's peak memory counter so {@see memory_get_peak_usage} reflects the current HTTP request,
 * queue job, Artisan command, or Octane tick — not prior work in the same long-lived worker process.
 */
final class MemoryPeakReset
{
    public static function beforeUnitOfWork(): void
    {
        if (\function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}
