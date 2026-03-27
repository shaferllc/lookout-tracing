<?php

declare(strict_types=1);

namespace Lookout\Tracing\Cron;

/**
 * Interval units for {@see MonitorSchedule::interval()} (Sentry-style).
 */
final class ScheduleUnit
{
    public const MINUTE = 'minute';

    public const HOUR = 'hour';

    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const YEAR = 'year';
}
