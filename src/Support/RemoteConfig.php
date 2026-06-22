<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Fetches the project's ingest config from the Lookout server (GET /api/config) so the dashboard
 * is the source of truth for which signals are captured/sent. Replaces the old per-signal
 * LOOKOUT_*_MONITORING_ENABLED env toggles and the narrower performance `sync_from_api` path.
 *
 * Pure mapping + HTTP fetch live here; caching and the boot-time config override live in the
 * Laravel service provider (Laravel-only concerns).
 */
final class RemoteConfig
{
    /**
     * Server signal key => `lookout-tracing` config path for that signal's enable flag.
     * Always-on server signals (errors, crons, feedback) have no SDK enable toggle and are omitted.
     *
     * @return array<string, string>
     */
    public static function enabledMap(): array
    {
        return [
            'traces' => 'performance.enabled',
            'profiles' => 'profiling.enabled',
            'logs' => 'logging.enabled',
            'jobs' => 'job_monitoring.enabled',
            'batches' => 'batch_monitoring.enabled',
            'mail' => 'mail_monitoring.enabled',
            'events' => 'event_monitoring.enabled',
            'notifications' => 'notification_monitoring.enabled',
            'models' => 'model_monitoring.enabled',
            'gates' => 'gate_monitoring.enabled',
            'metrics' => 'metrics.enabled',
            'dumps' => 'dumps.enabled',
            'rum' => 'rum.enabled',
            'crons' => 'cron_monitoring.enabled',
        ];
    }

    /**
     * Pure: turn a decoded /api/config document into `[config-path => bool]` enable overrides.
     * Unknown or missing signals are skipped so a partial/old payload never forces anything off.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, bool>
     */
    public static function enabledOverrides(array $remote): array
    {
        $signals = is_array($remote['signals'] ?? null) ? $remote['signals'] : [];

        $overrides = [];
        foreach (self::enabledMap() as $serverKey => $configPath) {
            $signal = $signals[$serverKey] ?? null;
            if (is_array($signal) && array_key_exists('enabled', $signal)) {
                $overrides[$configPath] = (bool) $signal['enabled'];
            }
        }

        return $overrides;
    }

    /**
     * GET {baseUri}/api/config authenticated with the project API key. Returns the decoded
     * document (`version`, `ttl`, `signals`) or null on any network/parse failure.
     *
     * @return array<string, mixed>|null
     */
    public static function fetch(string $baseUri, string $apiKey, int $timeoutSeconds = 5): ?array
    {
        $base = rtrim(trim($baseUri), '/');
        $key = trim($apiKey);
        if ($base === '' || $key === '') {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'X-Api-Key: '.$key,
                ]),
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($base.'/api/config', false, $context);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cache key for a project's config, scoped by API key so multiple keys never collide.
     */
    public static function cacheKey(string $apiKey): string
    {
        return 'lookout-tracing:remote-config:'.substr(hash('sha256', trim($apiKey)), 0, 16);
    }
}
