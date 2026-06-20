<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Prevents recursive monitoring when the SDK POSTs ingest payloads back into the same app host.
 */
final class IngestSelfMonitoring
{
    public const INTERNAL_REQUEST_HEADER = 'X-Lookout-Ingest-Internal';

    public static function isIngestPath(string $path): bool
    {
        return str_starts_with(ltrim($path, '/'), 'api/ingest');
    }

    public static function hostFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return strtolower((string) $parts['host']);
    }

    public static function applicationHost(): ?string
    {
        if (function_exists('app')) {
            try {
                if (app()->bound('config')) {
                    $appUrl = config('app.url');
                    if (is_string($appUrl) && trim($appUrl) !== '') {
                        return self::hostFromUrl($appUrl);
                    }
                }
            } catch (\Throwable) {
                // Fall back to APP_URL when Laravel is not bootstrapped.
            }
        }

        $env = getenv('APP_URL');
        if (is_string($env) && trim($env) !== '') {
            return self::hostFromUrl($env);
        }

        return null;
    }

    public static function isSameHostIngestUrl(string $url): bool
    {
        $ingestHost = self::hostFromUrl($url);
        $appHost = self::applicationHost();

        if ($ingestHost === null || $appHost === null) {
            return false;
        }

        return strcasecmp($ingestHost, $appHost) === 0;
    }

    /**
     * @return list<string> Extra HTTP header lines for {@see HttpTransport} stream contexts.
     */
    public static function internalIngestHeaderLines(string $url): array
    {
        if (! self::isSameHostIngestUrl($url)) {
            return [];
        }

        return [self::INTERNAL_REQUEST_HEADER.': 1'];
    }

    public static function shouldSkipMonitoringForPath(string $path, bool $internalHeader = false): bool
    {
        if (self::isIngestPath($path)) {
            return true;
        }

        return $internalHeader;
    }

    public static function shouldSkipTerminateFlushes(): bool
    {
        if (! function_exists('request')) {
            return false;
        }

        try {
            $req = request();
        } catch (\Throwable) {
            return false;
        }

        return self::shouldSkipMonitoring($req);
    }

    public static function shouldSkipMonitoring(object $request): bool
    {
        if (! method_exists($request, 'path')) {
            return false;
        }

        $path = (string) $request->path();
        $internal = method_exists($request, 'headers')
            && $request->headers->get(self::INTERNAL_REQUEST_HEADER) === '1';

        return self::shouldSkipMonitoringForPath($path, $internal);
    }
}
