<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Minimal GET helper for Lookout Sanctum JSON API (e.g. sync {@code performance_ingest_enabled}).
 */
final class LookoutManagementApi
{
    /**
     * Fetch a single project resource from {@code GET /api/v1/projects/{id}}.
     *
     * @return array<string, mixed>|null Decoded {@code data} object, or null on failure
     */
    public static function fetchProject(string $baseUri, string $bearerToken, string $projectId): ?array
    {
        $base = rtrim($baseUri, '/');
        $token = trim($bearerToken);
        $pid = trim($projectId);
        if ($base === '' || $token === '' || $pid === '') {
            return null;
        }

        $url = $base.'/api/v1/projects/'.rawurlencode($pid);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'Authorization: Bearer '.$token,
                ]),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? null;

        return is_array($data) ? $data : null;
    }
}
