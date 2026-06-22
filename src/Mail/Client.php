<?php

declare(strict_types=1);

namespace Lookout\Tracing\Mail;

use Lookout\Tracing\HttpTransport;

/**
 * PHP client for {@code POST /api/ingest/mail} sent-mail monitoring.
 */
final class Client
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     mail_ingest_path?: string|null,
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
     * @param  list<string>|null  $to
     * @param  array<string, mixed>|null  $meta
     */
    public static function captureSent(
        string $mailable,
        ?string $subject = null,
        ?array $to = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $traceId = null,
        ?array $meta = null,
    ): bool {
        $body = array_filter([
            'mailable' => $mailable,
            'subject' => $subject,
            'to' => $to !== null && $to !== [] ? array_values($to) : null,
            'environment' => $environment ?? (self::$config['environment'] ?? null),
            'release' => $release ?? (self::$config['release'] ?? null),
            'trace_id' => $traceId,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
        ], fn (mixed $v): bool => $v !== null);

        $url = self::mailUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            return false;
        }

        $res = HttpTransport::postJsonWithResponse($url, $key, $body, clientSampled: true);

        return (bool) ($res['ok'] ?? false);
    }

    private static function mailUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['mail_ingest_path'] ?? '/api/ingest/mail');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
