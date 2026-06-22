<?php

declare(strict_types=1);

namespace Lookout\Tracing\Notification;

use Lookout\Tracing\HttpTransport;

/**
 * PHP client for {@code POST /api/ingest/notification} sent-notification monitoring.
 */
final class Client
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     notification_ingest_path?: string|null,
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
    public static function captureSent(
        string $notification,
        string $channel,
        ?string $notifiableType = null,
        ?string $notifiableId = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $traceId = null,
        ?array $meta = null,
    ): bool {
        $body = array_filter([
            'notification' => $notification,
            'channel' => $channel,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'environment' => $environment ?? (self::$config['environment'] ?? null),
            'release' => $release ?? (self::$config['release'] ?? null),
            'trace_id' => $traceId,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
        ], fn (mixed $v): bool => $v !== null);

        $url = self::notificationUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return false;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body, clientSampled: true);

        return (bool) ($res['ok'] ?? false);
    }

    private static function notificationUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['notification_ingest_path'] ?? '/api/ingest/notification');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
