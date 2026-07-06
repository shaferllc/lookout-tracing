<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

/**
 * Pattern-matched fix suggestions for well-known Laravel/PHP errors — the
 * debug page's built-in "solution providers" (Ignition-style, string-based so
 * they also work on the fetch-back render path where no Throwable exists).
 *
 * Deliberately high-signal: a wrong suggestion is worse than none, so every
 * entry matches a distinctive class or message fragment. A host-configured
 * solution (SolutionsMiddleware / payload `solution`) always wins over these.
 */
final class ErrorSolutions
{
    /**
     * Each row: [class-fragment|null, message-fragment|null, suggestion].
     * Both fragments are matched case-insensitively; null means "any".
     *
     * @var list<array{0: ?string, 1: ?string, 2: string}>
     */
    private const RULES = [
        ['MissingAppKeyException', null, 'No application encryption key is set. Run `php artisan key:generate` and reload.'],
        [null, 'Vite manifest not found', 'Frontend assets have not been built. Run `npm run build`, or start the dev server with `npm run dev` / `composer run dev`.'],
        [null, 'SQLSTATE[HY000] [1049]', 'The configured database does not exist. Check DB_DATABASE in .env and create the database, then `php artisan config:clear`.'],
        [null, 'SQLSTATE[HY000] [2002]', 'Could not connect to the database server. Check that it is running and that DB_HOST / DB_PORT in .env are correct.'],
        [null, 'SQLSTATE[HY000] [1045]', 'Database authentication failed. Check DB_USERNAME / DB_PASSWORD in .env, then `php artisan config:clear`.'],
        [null, 'Base table or view not found', 'A table is missing. Run `php artisan migrate` (did a new migration land in this branch?).'],
        [null, 'could not find driver', 'The PDO driver for your database is not installed. Install the PHP extension (e.g. pdo_mysql, pdo_pgsql, pdo_sqlite) and restart PHP.'],
        [null, 'Unknown column', 'A column is missing or misspelled. Check for pending migrations (`php artisan migrate`) and recently renamed columns.'],
        ['TokenMismatchException', null, 'CSRF token mismatch (419). Ensure the form includes `@csrf` and the session cookie is being sent (check SESSION_DOMAIN / SESSION_SECURE_COOKIE for cross-domain setups).'],
        [null, 'Session store not set on request', 'The route is missing session middleware. Move it into the `web` middleware group or add `\Illuminate\Session\Middleware\StartSession`.'],
        ['RouteNotFoundException', 'Route [', 'A named route does not exist. Check the name in `route(...)` against `php artisan route:list --name=<name>` (route caches go stale: `php artisan route:clear`).'],
        ['ViewException', 'View [', 'The view file was not found. Check the path under resources/views and the dot-notation name (view caches go stale: `php artisan view:clear`).'],
        [null, 'View [', 'The view file was not found. Check the path under resources/views and the dot-notation name (view caches go stale: `php artisan view:clear`).'],
        ['BindingResolutionException', 'Target class', 'The container cannot resolve the class. Check the namespace/typo and any service-provider binding, then `php artisan config:clear` if config is cached.'],
        [null, 'Class "', 'The class cannot be autoloaded. Check the namespace matches the file path and run `composer dump-autoload`.'],
        [null, 'Maximum execution time', 'The request exceeded max_execution_time. Profile what is slow (see the timeline/queries panels below) — long work belongs in a queued job. As a stopgap raise max_execution_time or call set_time_limit().'],
        [null, 'Allowed memory size', 'The request ran out of memory. Chunk large queries (chunk()/cursor()/lazy()), avoid loading whole tables, or raise memory_limit as a stopgap.'],
        [null, 'cURL error 60', 'SSL certificate verification failed. Point curl.cainfo / openssl.cafile at an up-to-date CA bundle (never disable verification in production).'],
        [null, 'cURL error 6', 'DNS resolution failed for the request host. Check the hostname/URL and network/DNS from this server.'],
        [null, 'cURL error 28', 'The outbound HTTP request timed out. Check the remote service and consider a timeout + retry via Http::timeout()->retry().'],
        ['LockTimeoutException', null, 'An atomic lock could not be acquired in time. Something is holding the lock longer than expected — check for stuck jobs or raise the lock/block timeout.'],
        [null, 'Serialization of \'Closure\' is not allowed', 'A closure is being serialized — usually a closure passed into queued job properties or cached data. Replace it with an invokable class or primitive data.'],
        [null, 'No application encryption key', 'No application encryption key is set. Run `php artisan key:generate` and reload.'],
        [null, 'The payload is invalid', 'Decryption failed — the data was encrypted with a different APP_KEY. If APP_KEY changed, old cookies/sessions and encrypted values are unreadable (clear cookies; re-encrypt stored values).'],
        [null, 'unserialize(): Error at offset', 'Corrupt serialized data, often a cache entry written by different code. Run `php artisan cache:clear` and check for APP_KEY or serializer changes.'],
        [null, 'Call to a member function', 'A method was called on null. Trace which variable can be null on this line — a missing relation/find() result is the usual cause. Consider `?->`, `optional()`, or findOrFail().'],
    ];

    public static function suggest(?string $exceptionClass, ?string $message): ?string
    {
        $class = strtolower((string) $exceptionClass);
        $msg = strtolower((string) $message);

        foreach (self::RULES as [$classFragment, $messageFragment, $suggestion]) {
            if ($classFragment !== null && ! str_contains($class, strtolower($classFragment))) {
                continue;
            }
            if ($messageFragment !== null && ! str_contains($msg, strtolower($messageFragment))) {
                continue;
            }
            if ($classFragment === null && $messageFragment === null) {
                continue;
            }

            return $suggestion;
        }

        return null;
    }
}
