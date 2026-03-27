<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Lookout\Tracing\BreadcrumbBuffer;
use Symfony\Component\VarDumper\VarDumper;
use Throwable;

/**
 * Hooks {@see VarDumper} so {@code dump()} calls add breadcrumbs.
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
            if ($previous !== null) {
                $previous($var);
            }
        });
    }
}
