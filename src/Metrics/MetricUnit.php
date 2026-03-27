<?php

declare(strict_types=1);

namespace Lookout\Tracing\Metrics;

/**
 * Optional unit hint stored on each metric sample (OpenTelemetry-style short strings).
 */
final class MetricUnit
{
    public static function none(): string
    {
        return '';
    }

    public static function millisecond(): string
    {
        return 'ms';
    }

    public static function second(): string
    {
        return 's';
    }

    public static function byte(): string
    {
        return 'By';
    }
}
