<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Minimal JSON POST for trace ingest (no extra dependencies).
 */
final class HttpTransport
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function postJson(string $url, string $apiKey, array $body): bool
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Api-Key: '.$apiKey,
                ]),
                'content' => $json,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return false;
        }

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
    public static function postJsonWithResponse(string $url, string $apiKey, array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Api-Key: '.$apiKey,
                ]),
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
        $ok = $status !== null && $status >= 200 && $status < 300;

        return ['ok' => $ok, 'status' => $status, 'data' => $data];
    }
}
