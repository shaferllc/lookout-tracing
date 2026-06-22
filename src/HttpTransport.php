<?php

declare(strict_types=1);

namespace Lookout\Tracing;

use Lookout\Tracing\Support\IngestSelfMonitoring;

/**
 * Minimal JSON POST for trace ingest (no extra dependencies).
 */
final class HttpTransport
{
    /**
     * Set true at boot when remote config is active (the SDK samples at the dashboard's rates).
     * Only then is the `X-Lookout-Client-Sampled` header emitted, telling the server to skip its
     * own sampling for that signal so the rate is applied exactly once.
     */
    public static bool $emitClientSampledHeader = false;

    /**
     * Ingest paths (e.g. "/api/ingest/log") for signals the customer's env force-enables. Requests
     * to these carry X-Lookout-Env-Forced so the server accepts them even when the dashboard toggle
     * is off (env > site). Set at boot from the detected env overrides.
     *
     * @var list<string>
     */
    public static array $envForcedPaths = [];

    /**
     * The most recent `config_version` an ingest response reported this request. Compared at boot to
     * the cached config's version so a dashboard change is picked up within one ingest round-trip
     * instead of waiting for the cache TTL to expire.
     */
    public static ?string $lastSeenConfigVersion = null;

    /**
     * @param  array<string, mixed>  $body
     */
    public static function postJson(string $url, string $apiKey, array $body, bool $clientSampled = false): bool
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => self::requestHeaders($url, $apiKey, $clientSampled),
                'content' => $json,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return false;
        }

        $decoded = json_decode($result, true);
        self::rememberConfigVersion(is_array($decoded) ? $decoded : null);

        if (! isset($http_response_header) || ! is_array($http_response_header)) {
            return true;
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
            $code = (int) $m[1];

            return $code >= 200 && $code < 300;
        }

        return true;
    }

    /**
     * POST JSON and return status + decoded body when possible.
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int|null, data: array<string, mixed>|null}
     */
    public static function postJsonWithResponse(string $url, string $apiKey, array $body, bool $clientSampled = false): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => self::requestHeaders($url, $apiKey, $clientSampled),
                'content' => $json,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        $status = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($result === false) {
            return ['ok' => false, 'status' => $status, 'data' => null];
        }

        $decoded = json_decode($result, true);
        $data = is_array($decoded) ? $decoded : null;
        self::rememberConfigVersion($data);
        $ok = $status !== null && $status >= 200 && $status < 300;

        return ['ok' => $ok, 'status' => $status, 'data' => $data];
    }

    /**
     * POST JSON with limited retries on transport failure (no HTTP status) or given status codes (e.g. 429).
     *
     * @param  list<int>  $retryOnStatuses
     * @return array{ok: bool, status: int|null, data: array<string, mixed>|null}
     */
    public static function postJsonWithResponseRetries(
        string $url,
        string $apiKey,
        array $body,
        int $maxAttempts,
        int $delayMsBetweenAttempts,
        array $retryOnStatuses = [429],
        bool $clientSampled = false,
    ): array {
        $maxAttempts = max(1, $maxAttempts);
        $last = ['ok' => false, 'status' => null, 'data' => null];
        for ($i = 1; $i <= $maxAttempts; $i++) {
            $last = self::postJsonWithResponse($url, $apiKey, $body, $clientSampled);
            if ($last['ok']) {
                return $last;
            }
            $st = $last['status'];
            $shouldRetry = $i < $maxAttempts && ($st === null || in_array($st, $retryOnStatuses, true));
            if (! $shouldRetry) {
                return $last;
            }
            if ($delayMsBetweenAttempts > 0) {
                usleep($delayMsBetweenAttempts * 1000);
            }
        }

        return $last;
    }

    /**
     * @return non-empty-string
     */
    private static function requestHeaders(string $url, string $apiKey, bool $clientSampled = false): string
    {
        return implode("\r\n", self::headerLines($url, $apiKey, $clientSampled));
    }

    /**
     * Header lines for an ingest request. Public for testing. Adds `X-Lookout-Client-Sampled`
     * only when the caller already sampled this signal AND remote config is active.
     *
     * @return list<string>
     */
    public static function headerLines(string $url, string $apiKey, bool $clientSampled = false): array
    {
        $lines = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Api-Key: '.$apiKey,
            ...IngestSelfMonitoring::internalIngestHeaderLines($url),
        ];

        if ($clientSampled && self::$emitClientSampledHeader) {
            $lines[] = 'X-Lookout-Client-Sampled: 1';
        }

        if (self::urlIsEnvForced($url)) {
            $lines[] = 'X-Lookout-Env-Forced: 1';
        }

        return $lines;
    }

    /**
     * Record the `config_version` from an ingest response body, if present.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function rememberConfigVersion(?array $data): void
    {
        $version = $data['config_version'] ?? null;
        if (is_string($version) && $version !== '') {
            self::$lastSeenConfigVersion = $version;
        }
    }

    private static function urlIsEnvForced(string $url): bool
    {
        foreach (self::$envForcedPaths as $path) {
            if ($path !== '' && str_ends_with($url, $path)) {
                return true;
            }
        }

        return false;
    }
}
