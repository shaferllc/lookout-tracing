<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Detects explicit per-signal env overrides (LOOKOUT_*). An explicit env var WINS over the
 * dashboard's remote config — env > site. {@see detect()} is called from the config file so the
 * result is baked into cached config (env reads outside config files are unreliable once cached).
 *
 * Keyed by the server's ingest signal type so it lines up with {@see RemoteConfig} and the report
 * the dashboard stores. Only signals whose env var is explicitly set appear.
 */
final class EnvOverrides
{
    /** Signal type => its enable env var. */
    private const ENABLE_ENV = [
        'traces' => 'LOOKOUT_PERFORMANCE_ENABLED',
        'profiles' => 'LOOKOUT_PROFILING_ENABLED',
        'logs' => 'LOOKOUT_LOGS_ENABLED',
        'jobs' => 'LOOKOUT_JOB_MONITORING_ENABLED',
        'batches' => 'LOOKOUT_BATCH_MONITORING_ENABLED',
        'mail' => 'LOOKOUT_MAIL_MONITORING_ENABLED',
        'events' => 'LOOKOUT_EVENT_MONITORING_ENABLED',
        'notifications' => 'LOOKOUT_NOTIFICATION_MONITORING_ENABLED',
        'models' => 'LOOKOUT_MODEL_MONITORING_ENABLED',
        'gates' => 'LOOKOUT_GATE_MONITORING_ENABLED',
        'metrics' => 'LOOKOUT_METRICS_ENABLED',
        'dumps' => 'LOOKOUT_DUMPS_ENABLED',
        'rum' => 'LOOKOUT_RUM_ENABLED',
        'crons' => 'LOOKOUT_CRON_MONITORING_ENABLED',
        'security_audit' => 'LOOKOUT_SECURITY_AUDIT_ENABLED',
    ];

    /** Signal type => its sample-rate env var (only signals that sample client-side). */
    private const SAMPLE_ENV = [
        'errors' => 'LOOKOUT_REPORT_SAMPLE_RATE',
        'traces' => 'LOOKOUT_PERFORMANCE_SAMPLE_RATE',
        'profiles' => 'LOOKOUT_PROFILING_SAMPLE_RATE',
        'logs' => 'LOOKOUT_LOGS_SAMPLE_RATE',
        'metrics' => 'LOOKOUT_METRICS_SAMPLE_RATE',
        'dumps' => 'LOOKOUT_DUMPS_SAMPLE_RATE',
        'mail' => 'LOOKOUT_MAIL_MONITORING_SAMPLE_RATE',
        'notifications' => 'LOOKOUT_NOTIFICATION_MONITORING_SAMPLE_RATE',
        'models' => 'LOOKOUT_MODEL_MONITORING_SAMPLE_RATE',
        'gates' => 'LOOKOUT_GATE_MONITORING_SAMPLE_RATE',
    ];

    /** Signal type => the `lookout-tracing` config key holding its ingest path (for the env-forced marker). */
    private const INGEST_PATH_KEY = [
        'traces' => 'ingest_trace_path',
        'profiles' => 'profile_ingest_path',
        'logs' => 'log_ingest_path',
        'jobs' => 'job_ingest_path',
        'batches' => 'batch_ingest_path',
        'mail' => 'mail_ingest_path',
        'events' => 'event_ingest_path',
        'notifications' => 'notification_ingest_path',
        'models' => 'model_ingest_path',
        'gates' => 'gate_ingest_path',
        'metrics' => 'metric_ingest_path',
        'dumps' => 'dump_ingest_path',
        'rum' => 'rum_ingest_path',
        'crons' => 'cron_ingest_path',
        'security_audit' => 'security_ingest_path',
    ];

    /**
     * Explicitly-set env overrides, split into enable + sample rate, keyed by signal type.
     *
     * @return array{enabled: array<string, bool>, sample_rate: array<string, float>}
     */
    public static function detect(): array
    {
        $enabled = [];
        foreach (self::ENABLE_ENV as $type => $var) {
            $raw = self::readEnv($var);
            if ($raw !== null) {
                $enabled[$type] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $sample = [];
        foreach (self::SAMPLE_ENV as $type => $var) {
            $raw = self::readEnv($var);
            if ($raw !== null && is_numeric($raw)) {
                $sample[$type] = max(0.0, min(1.0, (float) $raw));
            }
        }

        return ['enabled' => $enabled, 'sample_rate' => $sample];
    }

    /**
     * The map reported to the dashboard via X-Lookout-Env-Overrides: `{type: {enabled?, sample_rate?}}`.
     *
     * @param  array{enabled?: array<string, bool>, sample_rate?: array<string, float>}  $overrides
     * @return array<string, array{enabled?: bool, sample_rate?: float}>
     */
    public static function reportMap(array $overrides): array
    {
        $out = [];
        foreach ($overrides['enabled'] ?? [] as $type => $value) {
            $out[$type]['enabled'] = (bool) $value;
        }
        foreach ($overrides['sample_rate'] ?? [] as $type => $value) {
            $out[$type]['sample_rate'] = (float) $value;
        }

        return $out;
    }

    /**
     * The config key holding the ingest path for a signal, or null for signals with no toggle.
     */
    public static function ingestPathKey(string $type): ?string
    {
        return self::INGEST_PATH_KEY[$type] ?? null;
    }

    private static function readEnv(string $var): ?string
    {
        $value = $_ENV[$var] ?? $_SERVER[$var] ?? getenv($var);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
