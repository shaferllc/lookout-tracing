<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Throwable;

/**
 * Fetches "seen before" stats for the current error from the Lookout dashboard
 * (GET /api/debug/error-stats, authenticated with the project ingest key) so
 * the local debug page can show occurrence count, first/last seen, status, and
 * a link to the issue.
 *
 * This is the one deliberate exception to the page's no-network rule: a single
 * short-timeout GET (fail-open, ~half a second worst case) made server-side so
 * the ingest key never reaches the browser. Disable with
 * `debug_page.remote_stats = false`.
 */
final class OccurrenceStatsClient
{
    private const PATH = '/api/debug/error-stats';

    private const TIMEOUT_SECONDS = 0.6;

    /**
     * @return array<string, mixed>|null decoded stats, or null when disabled/unreachable/not found
     */
    public function fetch(string $exceptionClass, string $message): ?array
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            $cfg = config('lookout-tracing');
            if (! is_array($cfg)) {
                return null;
            }
            $debugCfg = is_array($cfg['debug_page'] ?? null) ? $cfg['debug_page'] : [];
            if (($debugCfg['remote_stats'] ?? true) === false) {
                return null;
            }
            $base = $cfg['base_uri'] ?? null;
            $key = $cfg['api_key'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($base) || $base === '' || ! is_string($key) || $key === '') {
            return null;
        }

        // Never add network latency (or hit a live dashboard) from the test suite.
        try {
            if (function_exists('app') && app()->runningUnitTests()) {
                return null;
            }
        } catch (Throwable) {
            // no container — plain PHP host, proceed
        }

        $url = rtrim($base, '/').self::PATH.'?'.http_build_query([
            'exception_class' => substr($exceptionClass, 0, 512),
            'message' => substr($message, 0, 1024),
        ]);

        $body = $this->get($url, $key);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ($decoded['found'] ?? false) !== true) {
            return null;
        }

        return $decoded;
    }

    private function get(string $url, string $apiKey): ?string
    {
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    return null;
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT_MS => 300,
                    CURLOPT_TIMEOUT_MS => (int) (self::TIMEOUT_SECONDS * 1000),
                    CURLOPT_HTTPHEADER => ['X-Api-Key: '.$apiKey, 'Accept: application/json'],
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $body = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                return is_string($body) && $status === 200 ? $body : null;
            }

            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "X-Api-Key: {$apiKey}\r\nAccept: application/json\r\n",
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => false,
            ]]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) ? $body : null;
        } catch (Throwable) {
            return null;
        }
    }
}
