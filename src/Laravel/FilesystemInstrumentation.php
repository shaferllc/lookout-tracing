<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Wraps the configured Laravel disks in a {@see TracingFilesystemAdapter} so file read/write/delete
 * operations surface as child spans inside performance traces (Laravel filesystems fire no native
 * events). Opt-in via {@code filesystem_monitoring.enabled}; off by default given the breadth of the
 * change (it replaces resolved disks app-wide).
 */
final class FilesystemInstrumentation
{
    public static function register(Application $app): void
    {
        if (! self::enabled()) {
            return;
        }

        $app->booted(static function () use ($app): void {
            try {
                $manager = $app->make('filesystem');
                foreach (self::disksToTrace() as $name) {
                    $disk = $manager->disk($name);
                    if ($disk instanceof TracingFilesystemAdapter || ! $disk instanceof Filesystem) {
                        continue;
                    }
                    $manager->set($name, new TracingFilesystemAdapter($disk, $name));
                }
            } catch (Throwable) {
                // Never break the host application over filesystem instrumentation.
            }
        });
    }

    /**
     * Disks to wrap: an explicit {@code filesystem_monitoring.disks} list, else the default disk.
     *
     * @return list<string>
     */
    private static function disksToTrace(): array
    {
        $cfg = config('lookout-tracing.filesystem_monitoring');
        $disks = is_array($cfg) && is_array($cfg['disks'] ?? null)
            ? array_values(array_filter($cfg['disks'], 'is_string'))
            : [];

        if ($disks === []) {
            $default = config('filesystems.default');
            if (is_string($default) && $default !== '') {
                $disks = [$default];
            }
        }

        return array_values(array_unique($disks));
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $fsCfg = is_array($cfg['filesystem_monitoring'] ?? null) ? $cfg['filesystem_monitoring'] : [];
        if (empty($fsCfg['enabled'])) {
            return false;
        }

        $key = $cfg['api_key'] ?? null;
        $base = $cfg['base_uri'] ?? null;

        return is_string($key) && $key !== '' && is_string($base) && rtrim(trim($base), '/') !== '';
    }
}
