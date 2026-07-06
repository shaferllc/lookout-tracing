<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Illuminate\Contracts\Console\Kernel;
use Throwable;

/**
 * Ignition-style executable fixes: for a handful of errors whose remedy is a
 * safe artisan command, the debug page shows a "Run" button that POSTs to the
 * SDK's local action endpoint.
 *
 * Guard rails (all enforced again server-side in the endpoint):
 *  - local environment + debug mode only — never production;
 *  - fixed command whitelist, nothing user-supplied is executed;
 *  - the button carries an HMAC of the command signed with APP_KEY, so the
 *    endpoint only runs commands this class vouched for.
 */
final class RunnableSolutions
{
    /** Whitelist: action id → [artisan command, button label]. */
    private const ACTIONS = [
        'migrate' => ['migrate', 'Run php artisan migrate'],
        'config-clear' => ['config:clear', 'Run php artisan config:clear'],
        'cache-clear' => ['cache:clear', 'Run php artisan cache:clear'],
        'view-clear' => ['view:clear', 'Run php artisan view:clear'],
        'route-clear' => ['route:clear', 'Run php artisan route:clear'],
    ];

    /**
     * Which action (if any) plausibly fixes this error.
     */
    public static function match(?string $exceptionClass, ?string $message): ?string
    {
        $class = strtolower((string) $exceptionClass);
        $msg = strtolower((string) $message);

        return match (true) {
            str_contains($msg, 'base table or view not found'),
            str_contains($msg, 'unknown column') => 'migrate',
            str_contains($class, 'bindingresolutionexception') && str_contains($msg, 'target class') => 'config-clear',
            str_contains($msg, 'view [') && str_contains($msg, 'not found') => 'view-clear',
            str_contains($msg, 'unserialize(): error at offset') => 'cache-clear',
            str_contains($class, 'routenotfoundexception') => 'route-clear',
            default => null,
        };
    }

    /**
     * Everything the page needs to render the button for an action id.
     *
     * @return array{id: string, command: string, label: string, token: string}|null
     */
    public static function describe(string $actionId): ?array
    {
        if (! isset(self::ACTIONS[$actionId]) || ! self::allowed()) {
            return null;
        }
        $token = self::sign($actionId);
        if ($token === null) {
            return null;
        }
        [$command, $label] = self::ACTIONS[$actionId];

        return ['id' => $actionId, 'command' => $command, 'label' => $label, 'token' => $token];
    }

    /**
     * Verify + run. Returns [ok, output] — output is the artisan buffer or an
     * error sentence, capped for display.
     *
     * @return array{0: bool, 1: string}
     */
    public static function run(string $actionId, string $token): array
    {
        if (! self::allowed()) {
            return [false, 'Runnable solutions are only available in the local environment.'];
        }
        if (! isset(self::ACTIONS[$actionId])) {
            return [false, 'Unknown action.'];
        }
        $expected = self::sign($actionId);
        if ($expected === null || ! hash_equals($expected, $token)) {
            return [false, 'Invalid action token.'];
        }

        [$command] = self::ACTIONS[$actionId];

        try {
            /** @var Kernel $kernel */
            $kernel = app(Kernel::class);
            $exit = $kernel->call($command);
            $output = trim($kernel->output());

            return [$exit === 0, substr($output !== '' ? $output : 'Done (exit '.$exit.').', 0, 4000)];
        } catch (Throwable $e) {
            return [false, substr($e->getMessage(), 0, 500)];
        }
    }

    /** Local + debug only — the endpoint refuses everything else. */
    public static function allowed(): bool
    {
        try {
            return function_exists('app')
                && app()->environment('local')
                && (bool) config('app.debug', false);
        } catch (Throwable) {
            return false;
        }
    }

    private static function sign(string $actionId): ?string
    {
        try {
            $key = (string) config('app.key', '');
        } catch (Throwable) {
            return null;
        }
        if ($key === '') {
            return null;
        }

        return hash_hmac('sha256', 'lookout-action|'.$actionId, $key);
    }
}
