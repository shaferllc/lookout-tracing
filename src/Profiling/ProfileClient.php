<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

use Lookout\Tracing\HttpTransport;

/**
 * POST profiles to {@code /api/ingest/profile}.
 */
final class ProfileClient
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     profile_ingest_path?: string|null
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
     * @param  array<string, mixed>  $body  Ingest body: agent, format, data, optional trace_id, transaction, …
     */
    public static function sendProfile(array $body): bool
    {
        $url = self::profileUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return false;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body);

        return $res['ok'] === true;
    }

    private static function profileUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['profile_ingest_path'] ?? '/api/ingest/profile');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
