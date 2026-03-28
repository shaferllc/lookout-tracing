<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Parses a one-line Lookout connection string so Laravel apps can set a single {@code LOOKOUT_DSN}
 * instead of separate API key and host env vars.
 *
 * Supported form: {@code https://PROJECT_API_KEY@lookout.example.com} (optional port).
 * The API key is the URL userinfo; use percent-encoding if the key contains reserved characters.
 */
final class LookoutDsn
{
    /**
     * @return array{api_key: string|null, base_uri: string|null}
     */
    public static function parse(string $dsn): array
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return ['api_key' => null, 'base_uri' => null];
        }

        $parts = parse_url($dsn);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ['api_key' => null, 'base_uri' => null];
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['api_key' => null, 'base_uri' => null];
        }

        $user = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $apiKey = $user !== '' ? $user : null;

        $host = (string) $parts['host'];
        $base = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $base .= ':'.(int) $parts['port'];
        }

        $baseUri = $base !== '' ? $base : null;

        return ['api_key' => $apiKey, 'base_uri' => $baseUri];
    }

    /**
     * Build a one-line DSN from a project ingest key and Lookout base URL (scheme + host + optional port only).
     *
     * @return non-empty-string|null
     */
    public static function fromApiKeyAndBaseUri(string $apiKey, string $baseUri): ?string
    {
        $apiKey = trim($apiKey);
        $baseUri = trim($baseUri);
        if ($apiKey === '' || $baseUri === '') {
            return null;
        }

        if (! str_contains($baseUri, '://')) {
            $baseUri = 'https://'.$baseUri;
        }

        $parts = parse_url($baseUri);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $encodedKey = rawurlencode($apiKey);

        return $scheme.'://'.$encodedKey.'@'.$host.$port;
    }
}
