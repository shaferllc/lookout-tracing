<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * POST JSON to Lookout {@code /api/ingest} (or configured path) with the project API key.
 */
final class ErrorIngestClient
{
    /**
     * @param  array<string, mixed>  $payload  Body fields (api_key merged if missing)
     * @param  array{
     *     api_key: string,
     *     base_uri: string,
     *     error_ingest_path?: string|null
     * }  $config
     */
    public static function send(array $payload, array $config): bool
    {
        $key = $config['api_key'] ?? '';
        if (! is_string($key) || $key === '') {
            return false;
        }
        $base = isset($config['base_uri']) && is_string($config['base_uri'])
            ? rtrim($config['base_uri'], '/')
            : '';
        if ($base === '') {
            return false;
        }
        $path = $config['error_ingest_path'] ?? '/api/ingest';
        $path = '/'.ltrim((string) $path, '/');
        $url = $base.$path;
        $body = array_merge($payload, ['api_key' => $key]);

        return HttpTransport::postJson($url, $key, $body);
    }
}
