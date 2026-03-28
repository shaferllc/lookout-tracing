<?php

declare(strict_types=1);

namespace Lookout\Tracing\Cron;

/**
 * Check-in status values for Lookout cron ingest.
 */
final class CheckInStatus
{
    public static function inProgress(): string
    {
        return 'in_progress';
    }

    public static function ok(): string
    {
        return 'ok';
    }

    public static function error(): string
    {
        return 'error';
    }
}
