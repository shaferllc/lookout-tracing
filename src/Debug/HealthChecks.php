<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Throwable;

/**
 * Cheap environment sanity checks rendered as an "Environment warnings" panel
 * on the debug page (Flare-style). Every check must be filesystem/config only —
 * no network, no queries — because this runs while an error page is rendering.
 */
final class HealthChecks
{
    /**
     * @return list<array{level: string, message: string}>
     */
    public static function run(): array
    {
        if (! function_exists('config') || ! function_exists('app')) {
            return [];
        }

        $warnings = [];

        try {
            $env = (string) config('app.env', '');
            $debug = (bool) config('app.debug', false);

            if ($debug && $env === 'production') {
                $warnings[] = ['level' => 'error', 'message' => 'APP_DEBUG is enabled in production — stack traces and secrets can leak to visitors. Set APP_DEBUG=false.'];
            }
            if ((string) config('app.key', '') === '') {
                $warnings[] = ['level' => 'error', 'message' => 'APP_KEY is empty — encryption and sessions are broken. Run php artisan key:generate.'];
            }
            if ($env === 'production' && (string) config('queue.default', '') === 'sync') {
                $warnings[] = ['level' => 'warning', 'message' => 'The queue driver is "sync" in production — queued jobs run inline in the request.'];
            }
            if ($env !== 'production' && app()->configurationIsCached()) {
                $warnings[] = ['level' => 'warning', 'message' => 'Config is cached in a non-production environment — .env changes are being ignored. Run php artisan config:clear.'];
            }
            if ($env === 'production' && (string) config('session.driver', '') === 'array') {
                $warnings[] = ['level' => 'warning', 'message' => 'The session driver is "array" in production — sessions are discarded after every request.'];
            }
        } catch (Throwable) {
            // config not available — skip config-based checks
        }

        try {
            if (function_exists('storage_path')) {
                foreach (['logs', 'framework/views', 'framework/cache'] as $sub) {
                    $dir = storage_path($sub);
                    if (is_dir($dir) && ! is_writable($dir)) {
                        $warnings[] = ['level' => 'error', 'message' => 'storage/'.$sub.' is not writable — logging/compilation will fail.'];
                    }
                }
            }
        } catch (Throwable) {
            // storage helpers unavailable
        }

        return $warnings;
    }
}
