<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Lookout\Tracing\Reporting\ErrorReportClient;
use Throwable;

/**
 * Sends uncaught exceptions to {@code POST /api/ingest} via {@see ErrorReportClient} (pipeline, truncation, optional queue).
 */
final class ExceptionReporter
{
    /**
     * Report a throwable to Lookout when {@see config('lookout-tracing.report_exceptions')} is true and {@see api_key} is set.
     */
    public static function report(Throwable $e, ?Application $app = null): void
    {
        try {
            if (! self::reportingEnabled()) {
                return;
            }
            ErrorReportClient::instance()->reportThrowable($e, $app);
        } catch (Throwable) {
            // Never break the host app's exception pipeline
        }
    }

    private static function reportingEnabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || empty($cfg['report_exceptions'])) {
            return false;
        }
        $apiKey = $cfg['api_key'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            return false;
        }
        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';

        return $base !== '';
    }
}
