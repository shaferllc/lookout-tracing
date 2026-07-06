<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

/**
 * Builds a copy-pastable cURL command that replays the failing request, from
 * the (already-redacted) request details panel. Secret-bearing headers arrive
 * masked as [REDACTED] — the command reproduces the request shape and the
 * developer fills in live credentials themselves.
 */
final class CurlCommand
{
    /** Noise / recomputed-by-curl headers that don't help a replay. */
    private const SKIP_HEADERS = ['content-length', 'connection', 'host', 'accept-encoding'];

    /**
     * @param  array<string, mixed>  $request  RequestDetailsCollector output
     */
    public function render(array $request): ?string
    {
        $url = isset($request['url']) && is_string($request['url']) ? $request['url'] : '';
        if ($url === '') {
            return null;
        }
        $method = isset($request['method']) && is_string($request['method']) ? strtoupper($request['method']) : 'GET';

        $parts = ['curl'];
        if ($method !== 'GET') {
            $parts[] = '-X '.$method;
        }
        $parts[] = escapeshellarg($url);

        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (in_array($name, self::SKIP_HEADERS, true) || ! is_string($value)) {
                continue;
            }
            $parts[] = '-H '.escapeshellarg($name.': '.$value);
        }

        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        if ($body !== [] && $method !== 'GET') {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json)) {
                $parts[] = '--data '.escapeshellarg($json);
            }
        }

        return implode(" \\\n  ", $parts);
    }
}
