<?php

declare(strict_types=1);

namespace Lookout\Tracing\Auth;

use Lookout\Tracing\HttpTransport;

/**
 * PHP client for {@code POST /api/ingest/auth} authentication monitoring — login, logout, failed,
 * lockout, registered, password_reset, and verified events.
 */
final class Client
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     auth_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null
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

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function captureAuthEvent(
        string $eventType,
        ?string $guard,
        ?string $userId,
        ?string $userLabel,
        ?string $ip,
        ?string $userAgent,
        ?bool $remember,
        ?string $environment = null,
        ?string $release = null,
        ?string $traceId = null,
        ?array $meta = null,
    ): bool {
        $body = array_filter([
            'event_type' => $eventType,
            'guard' => $guard,
            'auth_user_id' => $userId,
            'auth_user_label' => $userLabel,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'remember' => $remember,
            'environment' => $environment ?? (self::$config['environment'] ?? null),
            'release' => $release ?? (self::$config['release'] ?? null),
            'trace_id' => $traceId,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
        ], fn (mixed $v): bool => $v !== null);

        $url = self::authUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return false;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body);

        return (bool) ($res['ok'] ?? false);
    }

    private static function authUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['auth_ingest_path'] ?? '/api/ingest/auth');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
