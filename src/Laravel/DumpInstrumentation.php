<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Lookout\Tracing\BreadcrumbBuffer;
use Lookout\Tracing\Dump\DumpIngestClient;
use Symfony\Component\VarDumper\VarDumper;
use Throwable;

/**
 * Hooks {@see VarDumper} so {@code dump()}/{@code dd()} calls add a breadcrumb and, when dump ingest is
 * enabled, capture the full value as a normalized tree to the Dumps watcher. Native dump output still
 * renders locally — we observe, never replace.
 */
final class DumpInstrumentation
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || ! class_exists(VarDumper::class)) {
            return;
        }
        self::$registered = true;

        $previous = VarDumper::setHandler(function ($var) use (&$previous): void {
            try {
                BreadcrumbBuffer::add(
                    'dump',
                    'Dump: '.(is_object($var) ? $var::class : get_debug_type($var)),
                    'debug',
                    [],
                    'dump'
                );
            } catch (Throwable) {
                // ignore
            }
            try {
                DumpIngestClient::instance()->capture($var);
            } catch (Throwable) {
                // ignore
            }
            if ($previous !== null) {
                $previous($var);
            }
        });
    }
}
