<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Central catalog of Lookout SDK monitoring env toggles (client-side).
 *
 * Each UI watcher maps to one or more env vars. Project Settings → Monitoring modes
 * must also allow the matching {@code *_ingest_enabled} gate on the server.
 */
final class MonitoringEnv
{
    /**
     * Resolve a boolean env var: explicit value wins; otherwise use the Laravel quick-start default.
     */
    public static function resolveEnabled(mixed $raw, bool $laravelQuickStartDefault): bool
    {
        if ($raw !== null && $raw !== '') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return $laravelQuickStartDefault;
    }

    /**
     * Lines appended by {@code php artisan lookout:install} when quick start is enabled.
     *
     * @return list<string>
     */
    public static function quickStartEnvLines(): array
    {
        return [
            'LOOKOUT_LARAVEL=true',
            '',
            '# Lookout monitoring (SDK client toggles — also enable ingest in Project → Settings → Monitoring modes)',
            'LOOKOUT_REPORT_EXCEPTIONS=true',
            'LOOKOUT_REPORT_HTTP_404=true',
            'LOOKOUT_TRACING_AUTO_FLUSH=true',
            '',
            '# Dedicated ingest watchers (Telescope-style)',
            'LOOKOUT_JOB_MONITORING_ENABLED=true',
            'LOOKOUT_MAIL_MONITORING_ENABLED=true',
            'LOOKOUT_EVENT_MONITORING_ENABLED=true',
            'LOOKOUT_NOTIFICATION_MONITORING_ENABLED=true',
            'LOOKOUT_MODEL_MONITORING_ENABLED=true',
            'LOOKOUT_CRON_MONITORING_ENABLED=true',
            '',
            '# Trace-derived watchers (Requests, Traces, Queries, Cache, HTTP client, Commands, Queues)',
            'LOOKOUT_PERFORMANCE_ENABLED=true',
            'LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE=true',
            'LOOKOUT_PERFORMANCE_SAMPLE_RATE=0.1',
            'LOOKOUT_PERFORMANCE_COLLECT_HTTP=true',
            'LOOKOUT_PERFORMANCE_COLLECT_DB=true',
            'LOOKOUT_PERFORMANCE_COLLECT_HTTP_CLIENT=true',
            'LOOKOUT_PERFORMANCE_COLLECT_CACHE=true',
            'LOOKOUT_PERFORMANCE_COLLECT_CONSOLE=true',
            'LOOKOUT_PERFORMANCE_COLLECT_QUEUE=true',
            'LOOKOUT_PERFORMANCE_COLLECT_REDIS=false',
            'LOOKOUT_PERFORMANCE_COLLECT_VIEW=false',
            '',
            '# Structured logs + custom metrics',
            'LOOKOUT_LOGS_ENABLED=true',
            'LOOKOUT_METRICS_ENABLED=true',
            '',
            '# Profiling (auto-profiles only when the Excimer extension is installed)',
            'LOOKOUT_PROFILING_ENABLED=false',
            'LOOKOUT_PROFILING_SAMPLE_RATE=0.05',
            '# Cooperative pulse sampler — opt in only for deliberate lookout_profiles() use; off by default',
            '# LOOKOUT_PROFILING_MANUAL_PULSE_FALLBACK=true',
            '',
            '# Browser RUM (Web Vitals + Livewire navigations + interaction timers)',
            'LOOKOUT_RUM_ENABLED=true',
            'LOOKOUT_RUM_LIVEWIRE_NAVIGATE=true',
        ];
    }

    /**
     * Reference catalog: UI area → primary SDK env var(s).
     *
     * @return array<string, array{
     *     label: string,
     *     primary_env: string,
     *     related_env: list<string>,
     *     server_gate: string|null,
     *     quick_start: bool
     * }>
     */
    public static function catalog(): array
    {
        return [
            'issues' => [
                'label' => 'Issues (errors)',
                'primary_env' => 'LOOKOUT_REPORT_EXCEPTIONS',
                'related_env' => ['LOOKOUT_REPORT_HTTP_404', 'LOOKOUT_REPORT_SAMPLE_RATE'],
                'server_gate' => null,
                'quick_start' => true,
            ],
            'requests' => [
                'label' => 'Requests',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => [
                    'LOOKOUT_PERFORMANCE_COLLECT_HTTP',
                    'LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE',
                    'LOOKOUT_TRACING_AUTO_FLUSH',
                    'LOOKOUT_PERFORMANCE_SAMPLE_RATE',
                ],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'jobs' => [
                'label' => 'Queues',
                'primary_env' => 'LOOKOUT_JOB_MONITORING_ENABLED',
                'related_env' => ['LOOKOUT_PERFORMANCE_COLLECT_QUEUE'],
                'server_gate' => 'job_ingest_enabled',
                'quick_start' => true,
            ],
            'commands' => [
                'label' => 'Commands',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => [
                    'LOOKOUT_PERFORMANCE_COLLECT_CONSOLE',
                    'LOOKOUT_PERFORMANCE_FLUSH_CLI_QUEUE',
                ],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'schedule' => [
                'label' => 'Schedule / crons',
                'primary_env' => 'LOOKOUT_CRON_MONITORING_ENABLED',
                'related_env' => [],
                'server_gate' => null,
                'quick_start' => true,
            ],
            'logs' => [
                'label' => 'Logs',
                'primary_env' => 'LOOKOUT_LOGS_ENABLED',
                'related_env' => ['LOOKOUT_LOGS_FLUSH_ON_TERMINATE', 'LOOKOUT_LOGS_MAX_BUFFER'],
                'server_gate' => 'log_ingest_enabled',
                'quick_start' => true,
            ],
            'mail' => [
                'label' => 'Mail',
                'primary_env' => 'LOOKOUT_MAIL_MONITORING_ENABLED',
                'related_env' => [],
                'server_gate' => 'mail_ingest_enabled',
                'quick_start' => true,
            ],
            'events' => [
                'label' => 'Events',
                'primary_env' => 'LOOKOUT_EVENT_MONITORING_ENABLED',
                'related_env' => ['LOOKOUT_EVENT_MONITORING_WILDCARD', 'LOOKOUT_EVENT_MONITORING_SAMPLE_EVERY'],
                'server_gate' => 'event_ingest_enabled',
                'quick_start' => true,
            ],
            'notifications' => [
                'label' => 'Notifications',
                'primary_env' => 'LOOKOUT_NOTIFICATION_MONITORING_ENABLED',
                'related_env' => [],
                'server_gate' => 'notification_ingest_enabled',
                'quick_start' => true,
            ],
            'models' => [
                'label' => 'Models',
                'primary_env' => 'LOOKOUT_MODEL_MONITORING_ENABLED',
                'related_env' => ['LOOKOUT_MODEL_MONITORING_NAMESPACE'],
                'server_gate' => 'model_ingest_enabled',
                'quick_start' => true,
            ],
            'gates' => [
                'label' => 'Gates',
                'primary_env' => 'LOOKOUT_GATE_MONITORING_ENABLED',
                'related_env' => [],
                'server_gate' => 'gate_ingest_enabled',
                'quick_start' => true,
            ],
            'database' => [
                'label' => 'Queries',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => [
                    'LOOKOUT_PERFORMANCE_COLLECT_DB',
                    'LOOKOUT_PERFORMANCE_SLOW_QUERY_MS',
                    'LOOKOUT_PERFORMANCE_QUERY_INSIGHTS',
                ],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'redis' => [
                'label' => 'Redis',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => ['LOOKOUT_PERFORMANCE_COLLECT_REDIS'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => false,
            ],
            'cache' => [
                'label' => 'Cache',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => ['LOOKOUT_PERFORMANCE_COLLECT_CACHE'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'http_client' => [
                'label' => 'HTTP client',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => ['LOOKOUT_PERFORMANCE_COLLECT_HTTP_CLIENT'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'traces' => [
                'label' => 'Traces',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => ['LOOKOUT_TRACING_AUTO_FLUSH', 'LOOKOUT_PERFORMANCE_SAMPLE_RATE'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'transactions' => [
                'label' => 'Transactions',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => ['LOOKOUT_PERFORMANCE_TAIL_SAMPLING'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'profiles' => [
                'label' => 'Profiles',
                'primary_env' => 'LOOKOUT_PROFILING_ENABLED',
                'related_env' => [
                    'LOOKOUT_PROFILING_SAMPLE_RATE',
                    'LOOKOUT_PROFILING_MANUAL_PULSE_FALLBACK',
                    'LOOKOUT_PERFORMANCE_ENABLED',
                ],
                'server_gate' => 'profiling_ingest_enabled',
                'quick_start' => false,
            ],
            'metrics' => [
                'label' => 'Metrics',
                'primary_env' => 'LOOKOUT_METRICS_ENABLED',
                'related_env' => ['LOOKOUT_METRICS_FLUSH_ON_TERMINATE', 'LOOKOUT_METRICS_MAX_BUFFER'],
                'server_gate' => 'metrics_ingest_enabled',
                'quick_start' => true,
            ],
            'rum' => [
                'label' => 'RUM',
                'primary_env' => 'LOOKOUT_RUM_ENABLED',
                'related_env' => ['LOOKOUT_RUM_LIVEWIRE_NAVIGATE', 'LOOKOUT_RUM_INGEST_PATH'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => true,
            ],
            'releases' => [
                'label' => 'Releases',
                'primary_env' => 'LOOKOUT_RELEASE',
                'related_env' => ['LOOKOUT_COMMIT_SHA', 'LOOKOUT_DEPLOYED_AT'],
                'server_gate' => null,
                'quick_start' => false,
            ],
            'views' => [
                'label' => 'Views',
                'primary_env' => 'LOOKOUT_PERFORMANCE_ENABLED',
                'related_env' => ['LOOKOUT_PERFORMANCE_COLLECT_VIEW'],
                'server_gate' => 'performance_ingest_enabled',
                'quick_start' => false,
            ],
        ];
    }
}
