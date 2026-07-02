<?php

declare(strict_types=1);

namespace Lookout\Tracing\Security;

use Lookout\Tracing\HttpTransport;

/**
 * Collects a periodic security-posture snapshot of the running app — file-integrity hashes,
 * environment/config flags, files exposed in the public web root, and a dependency count — and
 * sends it to {@code POST /api/ingest/security}. The Lookout server diffs each snapshot against
 * the last one to catch tampering and evaluates it for misconfiguration.
 *
 * Cheap and read-only: it hashes a fixed set of high-signal files (not the whole tree), so it is
 * safe to run on a request-lifecycle throttle. Nothing here reads secret *values* — only whether
 * flags are set and the digests of files — so no sensitive data leaves the app.
 */
final class SecurityAuditReporter
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /** Individually hashed for precise change attribution. */
    private const TRACKED_FILES = [
        '.env',
        'composer.json',
        'composer.lock',
        'public/index.php',
        'artisan',
    ];

    /** Files whose presence in the public web root is a red flag; reported for the server to score. */
    private const SUSPICIOUS_PUBLIC_FILES = [
        '.env', '.git', '.svn', 'wp-config.php', 'config.php',
        'phpinfo.php', 'info.php', 'test.php', 'shell.php', 'adminer.php',
        'composer.json', 'composer.lock',
    ];

    /**
     * @param  array{
     *     enabled?: bool,
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     security_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null,
     * }  $config
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function resetForTesting(): void
    {
        self::$config = [];
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::$config['enabled'] ?? false);
    }

    /**
     * Build the posture snapshot from the app rooted at $basePath.
     *
     * @return array<string, mixed>
     */
    public static function collect(string $basePath): array
    {
        $basePath = rtrim($basePath, '/');

        return [
            'reported_at' => gmdate('c'),
            'hashes' => self::fileHashes($basePath),
            'config' => self::configSnapshot(),
            'public_files' => ['found' => self::suspiciousPublicFiles($basePath)],
            'dependencies' => ['count' => self::dependencyCount($basePath)],
            'php_version' => PHP_VERSION,
            'framework_version' => self::frameworkVersion(),
            'environment' => self::$config['environment'] ?? null,
            'release' => self::$config['release'] ?? null,
        ];
    }

    /**
     * Collect and POST one snapshot. Returns true on HTTP 202.
     */
    public static function send(string $basePath): bool
    {
        if (! self::isEnabled()) {
            return false;
        }
        $key = (string) (self::$config['api_key'] ?? '');
        $url = self::ingestUrl();
        if ($key === '' || $url === '') {
            return false;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, self::collect($basePath));

        return ($res['status'] ?? 0) === 202;
    }

    /**
     * @return array<string, string>
     */
    private static function fileHashes(string $basePath): array
    {
        $hashes = [];
        foreach (self::TRACKED_FILES as $file) {
            $path = $basePath.'/'.$file;
            if (is_file($path) && is_readable($path)) {
                $digest = @md5_file($path);
                if ($digest !== false) {
                    $hashes[$file] = $digest;
                }
            }
        }

        return $hashes;
    }

    /**
     * Non-secret configuration flags. Values here are booleans/enums/URLs, never credentials.
     *
     * @return array<string, mixed>
     */
    private static function configSnapshot(): array
    {
        if (! function_exists('config')) {
            return [];
        }

        return [
            'app_env' => config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'app_url' => config('app.url'),
            'session_secure' => (bool) config('session.secure'),
            'session_httponly' => (bool) config('session.http_only'),
            'session_same_site' => config('session.same_site'),
            'db_connection' => config('database.default'),
            'mail_driver' => config('mail.default'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function suspiciousPublicFiles(string $basePath): array
    {
        $publicPath = function_exists('public_path') ? public_path() : $basePath.'/public';
        $found = [];
        foreach (self::SUSPICIOUS_PUBLIC_FILES as $file) {
            if (file_exists(rtrim($publicPath, '/').'/'.$file)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    private static function dependencyCount(string $basePath): int
    {
        $lock = $basePath.'/composer.lock';
        if (! is_file($lock) || ! is_readable($lock)) {
            return 0;
        }
        $decoded = json_decode((string) @file_get_contents($lock), true);
        if (! is_array($decoded)) {
            return 0;
        }

        return count($decoded['packages'] ?? []) + count($decoded['packages-dev'] ?? []);
    }

    private static function frameworkVersion(): ?string
    {
        if (function_exists('app')) {
            try {
                return app()->version();
            } catch (\Throwable) {
                // Not a full Laravel app context; fall through.
            }
        }

        return null;
    }

    private static function ingestUrl(): string
    {
        $base = isset(self::$config['base_uri']) && is_string(self::$config['base_uri'])
            ? rtrim(self::$config['base_uri'], '/') : '';
        if ($base === '') {
            return '';
        }
        $path = (string) (self::$config['security_ingest_path'] ?? '/api/ingest/security');

        return $base.'/'.ltrim($path, '/');
    }
}
