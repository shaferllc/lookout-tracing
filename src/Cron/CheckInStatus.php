<?php

declare(strict_types=1);

namespace Lookout\Tracing\Cron;

/**
 * Check-in status values for Lookout cron ingest (Sentry Crons–compatible names).
 *
 * @see https://docs.sentry.io/platforms/php/crons/
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
