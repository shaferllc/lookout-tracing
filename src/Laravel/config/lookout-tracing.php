<?php

declare(strict_types=1);

use Lookout\Tracing\Performance\RateSampler;
use Lookout\Tracing\Support\EnvOverrides;
use Lookout\Tracing\Support\LookoutDsn;
use Lookout\Tracing\Support\MonitoringEnv;

$lookoutDsn = LookoutDsn::parse(trim((string) env('LOOKOUT_DSN', '')));

$trimEnvUrl = static function (string $key): ?string {
    $v = env($key);
    if (! is_string($v)) {
        return null;
    }
    $t = rtrim(trim($v), '/');

    return $t !== '' ? $t : null;
};

$servicesLookoutUrl = null;
if (function_exists('config')) {
    $u = config('services.lookout.url');
    if (is_string($u)) {
        $t = rtrim(trim($u), '/');
        $servicesLookoutUrl = $t !== '' ? $t : null;
    }
}

$baseUri = $lookoutDsn['base_uri']
    ?? $trimEnvUrl('LOOKOUT_BASE_URI')
    ?? $trimEnvUrl('LOOKOUT_URL')
    ?? $servicesLookoutUrl
    ?? $trimEnvUrl('APP_URL');

$apiKey = $lookoutDsn['api_key'] ?? env('LOOKOUT_API_KEY');
$apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : null;

$laravelQuickStart = filter_var((string) env('LOOKOUT_LARAVEL', ''), FILTER_VALIDATE_BOOLEAN);

$reportExceptions = env('LOOKOUT_REPORT_EXCEPTIONS');
$reportExceptions = $reportExceptions !== null
    ? filter_var($reportExceptions, FILTER_VALIDATE_BOOLEAN)
    : $laravelQuickStart;

$tracingAutoFlush = env('LOOKOUT_TRACING_AUTO_FLUSH');
$tracingAutoFlush = $tracingAutoFlush !== null
    ? filter_var($tracingAutoFlush, FILTER_VALIDATE_BOOLEAN)
    : $laravelQuickStart;

return [
    /*
    |--------------------------------------------------------------------------
    | Disable all Lookout tracing / reporting clients (no-op)
    |--------------------------------------------------------------------------
    */
    'disabled' => env('LOOKOUT_DISABLED', false),
    /*
    |--------------------------------------------------------------------------
    | Lookout API key
    |--------------------------------------------------------------------------
    |
    | Set LOOKOUT_API_KEY, or use a single LOOKOUT_DSN (https://KEY@host) from php artisan lookout:install.
    |
    */
    'api_key' => $apiKey,

    /*
    |--------------------------------------------------------------------------
    | Lookout base URL
    |--------------------------------------------------------------------------
    |
    | Scheme + host + optional port, no trailing slash. Resolved from LOOKOUT_DSN, then LOOKOUT_BASE_URI,
    | LOOKOUT_URL, config('services.lookout.url'), then APP_URL (self-hosted Lookout on the same URL).
    |
    */
    'base_uri' => $baseUri,

    'ingest_trace_path' => '/api/ingest/trace',

    'cron_ingest_path' => '/api/ingest/cron',

    'job_ingest_path' => '/api/ingest/job',

    'mail_ingest_path' => env('LOOKOUT_MAIL_INGEST_PATH', '/api/ingest/mail'),

    'event_ingest_path' => env('LOOKOUT_EVENT_INGEST_PATH', '/api/ingest/event'),

    'notification_ingest_path' => env('LOOKOUT_NOTIFICATION_INGEST_PATH', '/api/ingest/notification'),

    'model_ingest_path' => env('LOOKOUT_MODEL_INGEST_PATH', '/api/ingest/model'),

    'gate_ingest_path' => env('LOOKOUT_GATE_INGEST_PATH', '/api/ingest/gate'),

    'profile_ingest_path' => '/api/ingest/profile',

    /*
    |--------------------------------------------------------------------------
    | Automatic profiling (Excimer)
    |--------------------------------------------------------------------------
    |
    | When enabled AND the Excimer PECL extension is installed, a sampled fraction of
    | transactions (web requests, console commands, queue jobs) are CPU/wall profiled and
    | POSTed to /api/ingest/profile automatically — no ProfileClient::sendProfile() calls.
    | Without Excimer this is a silent no-op. Profiling rides on performance instrumentation,
    | so keep performance.enabled = true. Each uploaded profile counts toward your event quota,
    | so keep sample_rate low in production. event_type: 'wall' (default) or 'cpu'.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Remote config
    |--------------------------------------------------------------------------
    |
    | When enabled, the SDK fetches GET /api/config from Lookout (authenticated with this
    | project's api_key) and lets the dashboard decide which signals are captured/sent. The
    | config is cached for `ttl` seconds and refreshed after the response (never blocking a
    | request); until the first fetch lands, the built-in defaults below apply. This replaces
    | the per-signal LOOKOUT_*_MONITORING_ENABLED env toggles and the old performance sync.
    |
    */
    'remote_config' => [
        'enabled' => filter_var(env('LOOKOUT_REMOTE_CONFIG', true), FILTER_VALIDATE_BOOLEAN),
        'ttl' => (int) env('LOOKOUT_REMOTE_CONFIG_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Env overrides (env > site)
    |--------------------------------------------------------------------------
    |
    | Explicitly-set per-signal env vars (LOOKOUT_*_ENABLED / *_SAMPLE_RATE) win over the
    | dashboard's remote config. Detected here (in the config file, so it survives config caching)
    | and applied on top of remote config at boot. Reported to the dashboard so it can show which
    | signals your environment is pinning.
    |
    */
    'env_overrides' => EnvOverrides::detect(),

    'profiling' => [
        'enabled' => false,
        'sample_rate' => (float) env('LOOKOUT_PROFILING_SAMPLE_RATE', $laravelQuickStart ? 0.05 : 0.0),
        'period_us' => (int) env('LOOKOUT_PROFILING_PERIOD_US', 10000),
        'event_type' => env('LOOKOUT_PROFILING_EVENT_TYPE', 'wall'),
        'min_duration_ms' => (int) env('LOOKOUT_PROFILING_MIN_DURATION_MS', 0),
        'max_samples' => (int) env('LOOKOUT_PROFILING_MAX_SAMPLES', 10000),
        // Off by default: without Excimer the cooperative pulse sampler only snapshots the
        // begin/end of a capture — both inside the framework/middleware pipeline — so an
        // auto-profiled request yields a profile dominated by the pipeline and the SDK's own
        // frames rather than real application work. Opt in explicitly for deliberate
        // lookout_profiles()->time()/profile() cooperative sampling.
        'manual_pulse_fallback' => MonitoringEnv::resolveEnabled(env('LOOKOUT_PROFILING_MANUAL_PULSE_FALLBACK'), false),
    ],

    'log_ingest_path' => env('LOOKOUT_LOG_INGEST_PATH', '/api/ingest/log'),

    /*
    |--------------------------------------------------------------------------
    | Structured log ingest (lookout_logger + Monolog)
    |--------------------------------------------------------------------------
    |
    | When enabled, lookout_logger()->info(...) buffers rows for POST /api/ingest/log.
    | With LOOKOUT_LOGS_FLUSH_ON_TERMINATE=true (default), Laravel flushes at the end of each
    | request (terminating callback). Call lookout_logger()->flush() manually in long workers.
    |
    */
    'logging' => [
        'enabled' => $laravelQuickStart,
        'sample_rate' => (float) env('LOOKOUT_LOGS_SAMPLE_RATE', 1.0),
        'flush_on_terminate' => (bool) env('LOOKOUT_LOGS_FLUSH_ON_TERMINATE', true),
        'max_buffer' => (int) env('LOOKOUT_LOGS_MAX_BUFFER', 50),
    ],

    'metric_ingest_path' => env('LOOKOUT_METRIC_INGEST_PATH', '/api/ingest/metric'),

    'dump_ingest_path' => env('LOOKOUT_DUMP_INGEST_PATH', '/api/ingest/dump'),

    /*
    |--------------------------------------------------------------------------
    | Dump capture ingest (lookout_dump + native dump()/dd())
    |--------------------------------------------------------------------------
    |
    | When enabled (opt-in; tie to instrumentation.dump for native dump()/dd() capture), values are
    | serialized into a normalized, redacted tree and buffered for POST /api/ingest/dump, flushed at
    | the end of each request. Heavy entries, so batches and per-request counts are kept small. Secrets
    | are redacted by key before they ever leave the process.
    |
    */
    'dumps' => [
        'enabled' => false,
        'sample_rate' => (float) env('LOOKOUT_DUMPS_SAMPLE_RATE', 1.0),
        'flush_on_terminate' => (bool) env('LOOKOUT_DUMPS_FLUSH_ON_TERMINATE', true),
        'max_batch' => (int) env('LOOKOUT_DUMPS_MAX_BATCH', 20),
        'max_per_request' => (int) env('LOOKOUT_DUMPS_MAX_PER_REQUEST', 100),
        'serializer' => [
            'max_depth' => (int) env('LOOKOUT_DUMPS_MAX_DEPTH', 6),
            'max_children' => (int) env('LOOKOUT_DUMPS_MAX_CHILDREN', 100),
            'max_string' => (int) env('LOOKOUT_DUMPS_MAX_STRING', 8192),
            'max_total_bytes' => (int) env('LOOKOUT_DUMPS_MAX_TOTAL_BYTES', 262144),
        ],
    ],

    'rum_ingest_path' => env('LOOKOUT_RUM_INGEST_PATH', '/api/ingest/rum'),

    /*
    |--------------------------------------------------------------------------
    | Browser Real User Monitoring (POST /api/ingest/rum)
    |--------------------------------------------------------------------------
    |
    | When enabled, {@see Lookout\Tracing\Laravel\RumScript} injects lookout-rum.js
    | into Blade layouts. Requires performance.enabled (same server gate as traces).
    | Defaults on with LOOKOUT_LARAVEL=true when API key and base URI resolve.
    |
    */
    'rum' => [
        'enabled' => $laravelQuickStart,
        'ingest_path' => env('LOOKOUT_RUM_INGEST_PATH', '/api/ingest/rum'),
        'livewire_navigate' => filter_var(env('LOOKOUT_RUM_LIVEWIRE_NAVIGATE', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom metrics (lookout_metrics — counters, gauges, distributions)
    |--------------------------------------------------------------------------
    |
    | When enabled, samples buffer for POST /api/ingest/metric. Each point can carry the active
    | trace id for correlation in the Lookout UI. Workers should call flush() on a schedule.
    |
    */
    'metrics' => [
        'enabled' => $laravelQuickStart,
        'sample_rate' => (float) env('LOOKOUT_METRICS_SAMPLE_RATE', 1.0),
        'flush_on_terminate' => (bool) env('LOOKOUT_METRICS_FLUSH_ON_TERMINATE', true),
        'max_buffer' => (int) env('LOOKOUT_METRICS_MAX_BUFFER', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue job monitoring (POST /api/ingest/job)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel queue workers report in_progress → ok/error runs to Lookout.
    | Defaults on with LOOKOUT_LARAVEL=true; requires a resolved API key and base URI.
    | Respect project job_ingest_enabled on the server (403 when off).
    |
    */
    'job_monitoring' => [
        'enabled' => $laravelQuickStart,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue batch monitoring (POST /api/ingest/batch)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel reports Bus batch lifecycle (BatchDispatched, BatchFinished, BatchCanceled)
    | to Lookout (Telescope Batch Watcher). Defaults on with LOOKOUT_LARAVEL=true; requires a resolved
    | API key and base URI. Respect project batch_ingest_enabled on the server (403 when off).
    |
    */
    'batch_monitoring' => [
        'enabled' => $laravelQuickStart,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail monitoring (POST /api/ingest/mail)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel reports MessageSent events to Lookout (Telescope Mail Watcher).
    | Defaults on with LOOKOUT_LARAVEL=true. Respect project mail_ingest_enabled on the server.
    |
    */
    'mail_monitoring' => [
        'enabled' => $laravelQuickStart,
        'sample_rate' => (float) env('LOOKOUT_MAIL_MONITORING_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain event monitoring (POST /api/ingest/event)
    |--------------------------------------------------------------------------
    |
    | When enabled, dispatches are buffered and flushed at end of request (or manually).
    | Use allowlist for specific event classes, or wildcard=true with ignore_prefixes.
    | Defaults on with LOOKOUT_LARAVEL=true. Respect project event_ingest_enabled on the server.
    |
    */
    'event_monitoring' => [
        'enabled' => $laravelQuickStart,
        'wildcard' => (bool) env('LOOKOUT_EVENT_MONITORING_WILDCARD', false),
        'allowlist' => [],
        'ignore_prefixes' => ['Illuminate\\', 'Laravel\\', 'Livewire\\'],
        'wildcard_sample_every' => max(1, (int) env('LOOKOUT_EVENT_MONITORING_SAMPLE_EVERY', 1)),
        'flush_on_terminate' => (bool) env('LOOKOUT_EVENT_MONITORING_FLUSH_ON_TERMINATE', true),
        'max_buffer' => (int) env('LOOKOUT_EVENT_MONITORING_MAX_BUFFER', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification monitoring (POST /api/ingest/notification)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel reports NotificationSent events to Lookout.
    | Defaults on with LOOKOUT_LARAVEL=true. Respect project notification_ingest_enabled on the server.
    |
    */
    'notification_monitoring' => [
        'enabled' => $laravelQuickStart,
        'sample_rate' => (float) env('LOOKOUT_NOTIFICATION_MONITORING_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cron / schedule check-ins (POST /api/ingest/cron)
    |--------------------------------------------------------------------------
    |
    | When enabled, {@see Lookout\Tracing\Cron\Client} sends monitor check-ins.
    | Defaults on with LOOKOUT_LARAVEL=true. No server-side project gate (always accepted when keyed).
    |
    */
    'cron_monitoring' => [
        'enabled' => $laravelQuickStart,
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent model monitoring (POST /api/ingest/model)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel buffers eloquent created/updated/deleted events for App\ models
    | (configurable). Only attribute names are sent on update — never values.
    | Defaults on with LOOKOUT_LARAVEL=true. Respect project model_ingest_enabled on the server.
    |
    */
    'model_monitoring' => [
        'enabled' => $laravelQuickStart,
        'sample_rate' => (float) env('LOOKOUT_MODEL_MONITORING_SAMPLE_RATE', 1.0),
        'namespace_prefix' => env('LOOKOUT_MODEL_MONITORING_NAMESPACE', 'App\\'),
        'allowlist' => [],
        'ignore_prefixes' => ['Illuminate\\', 'Laravel\\', 'Livewire\\'],
        'ignore_change_attributes' => ['updated_at', 'created_at'],
        'flush_on_terminate' => (bool) env('LOOKOUT_MODEL_MONITORING_FLUSH_ON_TERMINATE', true),
        'max_buffer' => (int) env('LOOKOUT_MODEL_MONITORING_MAX_BUFFER', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization gate monitoring (POST /api/ingest/gate)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel buffers authorization gate/policy evaluations (GateEvaluated):
    | ability, allow/deny result, the target type, and the acting user id only (no names/values).
    | Defaults on with LOOKOUT_LARAVEL=true. Respects project gate_ingest_enabled on the server.
    |
    | Gate checks fire on nearly every request and are high volume; sample_rate randomly keeps
    | that fraction of evaluations client-side (1.0 = keep all, 0.01 = keep ~1%, 0.0 = keep none).
    |
    */
    'gate_monitoring' => [
        'enabled' => $laravelQuickStart,
        'sample_rate' => (float) env('LOOKOUT_GATE_MONITORING_SAMPLE_RATE', 1.0),
        'allowlist' => [],
        'ignore_abilities' => [],
        'flush_on_terminate' => (bool) env('LOOKOUT_GATE_MONITORING_FLUSH_ON_TERMINATE', true),
        'max_buffer' => (int) env('LOOKOUT_GATE_MONITORING_MAX_BUFFER', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace ingest HTTP (retries)
    |--------------------------------------------------------------------------
    |
    | On 429 or connection failures, Tracer::flushWithResult() may retry before giving up.
    | Status list is comma-separated HTTP codes (default: 429 only). 403 is never retried.
    |
    */
    'trace_ingest' => [
        'max_attempts' => (int) env('LOOKOUT_TRACE_INGEST_MAX_ATTEMPTS', 1),
        'retry_delay_ms' => (int) env('LOOKOUT_TRACE_INGEST_RETRY_DELAY_MS', 250),
        'retry_statuses' => array_values(array_filter(array_map(
            static fn (string $s): int => (int) trim($s),
            explode(',', (string) env('LOOKOUT_TRACE_INGEST_RETRY_STATUSES', '429'))
        ), static fn (int $c): bool => $c > 0)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error ingest (uncaught exceptions)
    |--------------------------------------------------------------------------
    |
    | When true, registers a reportable handler that POSTs to error_ingest_path with breadcrumbs
    | and trace fields. Enable explicitly in each environment (see LOOKOUT_REPORT_EXCEPTIONS).
    |
    */
    'report_exceptions' => $reportExceptions,

    /*
    |--------------------------------------------------------------------------
    | HTTP 404 reporting
    |--------------------------------------------------------------------------
    |
    | When true (and report_exceptions is enabled), completed HTTP responses with status 404
    | are sent to Lookout as handled warning events. Laravel normally skips NotFoundHttpException
    | in the exception reporter, so this listens on RequestHandled instead.
    |
    */
    'report_http_404' => filter_var(env('LOOKOUT_REPORT_HTTP_404', true), FILTER_VALIDATE_BOOLEAN),

    'error_ingest_path' => env('LOOKOUT_ERROR_INGEST_PATH', '/api/ingest'),

    'environment' => env('APP_ENV'),

    'release' => env('LOOKOUT_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Deployment markers (traces, errors, profiles)
    |--------------------------------------------------------------------------
    |
    | Optional git SHA and deploy/build time sent with performance traces and error reports so
    | regressions line up with POST /api/ingest/deploy rows. When empty, common platform variables
    | are used (SOURCE_VERSION, GITHUB_SHA, RENDER_GIT_COMMIT, VERCEL_GIT_COMMIT_SHA, …).
    |
    */
    'commit_sha' => env('LOOKOUT_COMMIT_SHA'),

    'deployed_at' => env('LOOKOUT_DEPLOYED_AT'),

    /*
    |--------------------------------------------------------------------------
    | Framework breadcrumbs (HTTP, Artisan, queue, optional DB / log / events)
    |--------------------------------------------------------------------------
    |
    | Breadcrumbs are attached to the next error report. They reset at each route match, Artisan
    | command, or queue job so long-lived PHP workers (Octane, queue:work) do not leak context.
    |
    */
    'breadcrumbs_max' => (int) env('LOOKOUT_BREADCRUMBS_MAX', 50),

    'instrumentation' => [
        'enabled' => env('LOOKOUT_INSTRUMENTATION_ENABLED', true),

        /*
         * When true, enables optional breadcrumb + performance collectors (cache, Redis, views,
         * outbound HTTP, response detail, SQL sample breadcrumbs, DB transaction breadcrumbs) and
         * turns on performance collectors for cache, Redis, views, and log spans. Requires
         * performance.enabled for trace collectors.
         */
        'comprehensive_collection' => env('LOOKOUT_INSTRUMENT_COMPREHENSIVE_COLLECTION', false),

        'http' => env('LOOKOUT_INSTRUMENT_HTTP', true),
        'console' => env('LOOKOUT_INSTRUMENT_CONSOLE', true),
        'queue' => env('LOOKOUT_INSTRUMENT_QUEUE', true),

        /*
         * SQL breadcrumbs (can be noisy). database_sample_every: 1 = every query, 5 = every 5th.
         * Enabled by default so error reports include sampled SQL context; set false to disable.
         */
        'database' => env('LOOKOUT_INSTRUMENT_DATABASE', true),
        'database_sample_every' => (int) env('LOOKOUT_INSTRUMENT_DATABASE_SAMPLE_EVERY', 5),

        /*
         * DB transaction beginning / commit / rollback breadcrumbs (Illuminate database events).
         */
        'database_transactions' => env('LOOKOUT_INSTRUMENT_DATABASE_TRANSACTIONS', false),

        /*
         * Maps MessageLogged to breadcrumbs (very noisy in debug).
         */
        'log' => env('LOOKOUT_INSTRUMENT_LOG', false),

        /*
         * List of application event class names to record (e.g. App\Events\OrderShipped::class).
         */
        'application_event_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LOOKOUT_INSTRUMENT_EVENT_ALLOWLIST', ''))
        ))),

        /*
         * Record every non-framework event name (heavy). Ignores namespaces in application_event_ignore_prefixes.
         */
        'application_events_wildcard' => env('LOOKOUT_INSTRUMENT_EVENTS_WILDCARD', false),

        'application_event_ignore_prefixes' => ['Illuminate\\', 'Laravel\\', 'Livewire\\'],

        /*
         * Optional breadcrumb recorders. Requires instrumentation.enabled.
         */
        'cache' => env('LOOKOUT_INSTRUMENT_CACHE', true),
        'redis' => env('LOOKOUT_INSTRUMENT_REDIS', false),
        'views' => env('LOOKOUT_INSTRUMENT_VIEWS', false),
        'outbound_http' => env('LOOKOUT_INSTRUMENT_OUTBOUND_HTTP', false),
        'response_detail' => env('LOOKOUT_INSTRUMENT_RESPONSE_DETAIL', false),
        'dump' => env('LOOKOUT_INSTRUMENT_DUMP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error report client (middleware, truncation, sampling, queued send)
    |--------------------------------------------------------------------------
    |
    | Enrich payload, trim to API limits, optional shutdown flush. Defaults preserve
    | immediate POST behavior unless you enable queuing.
    |
    */
    'reporting' => [
        'disabled' => env('LOOKOUT_REPORTING_DISABLED', false),

        /*
         * When true, payloads are buffered and sent on shutdown (see also send_immediately).
         */
        'queue' => env('LOOKOUT_REPORT_QUEUE', false),

        /*
         * When true, each report is POSTed immediately (queue is skipped).
         */
        'send_immediately' => env('LOOKOUT_REPORT_SEND_IMMEDIATELY', true),

        /*
         * Random client-side drop rate for uncaught reports (0.0–1.0). 1.0 = keep all.
         */
        'sample_rate' => (float) env('LOOKOUT_REPORT_SAMPLE_RATE', 1.0),

        /*
         * Optional list of ReportMiddlewareInterface class names (container-resolved).
         * Empty = default stack (application + request + git + attributes + solutions).
         */
        'middleware' => [],

        /*
         * AttributeProviderInterface class names; merged into context.attributes.
         */
        'attribute_providers' => [],

        /*
         * First matching string becomes the ingest "solution" field when empty.
         */
        'client_solutions' => [],

        /*
         * Include normalized function arguments on structured stack_frames when PHP provides them.
         * PHP may omit args when zend.exception_ignore_args=1 (default in some builds).
         */
        'include_stack_arguments' => env('LOOKOUT_REPORT_STACK_ARGUMENTS', true),

        'truncation' => [
            'max_message_length' => (int) env('LOOKOUT_REPORT_MAX_MESSAGE', 131_072),
            'max_stack_trace_bytes' => (int) env('LOOKOUT_REPORT_MAX_STACK', 524_288),
            'max_stack_frames' => (int) env('LOOKOUT_REPORT_MAX_FRAMES', 200),
            'max_stack_frame_args_json' => (int) env('LOOKOUT_REPORT_MAX_STACK_ARGS_JSON', 4096),
            'max_breadcrumbs' => (int) env('LOOKOUT_REPORT_MAX_BREADCRUMBS', 50),
            'max_breadcrumb_message' => (int) env('LOOKOUT_REPORT_MAX_CRUMB_MSG', 2000),
            'max_breadcrumb_data_json' => (int) env('LOOKOUT_REPORT_MAX_CRUMB_DATA', 8192),
            'max_context_json' => (int) env('LOOKOUT_REPORT_MAX_CONTEXT', 262_144),
        ],

        /*
         * When enabled, error reports may include grouping_slow_path + grouping_db_time_ms so the server
         * fingerprints slow / DB-heavy occurrences separately (route + DB time bucket overlay).
         * Requires LOOKOUT_PERFORMANCE_ENABLED and recorded spans in the same request.
         */
        'performance_grouping' => [
            'enabled' => env('LOOKOUT_REPORT_PERFORMANCE_GROUPING', false),
            'slow_root_transaction_ms' => (int) env('LOOKOUT_REPORT_GROUPING_ROOT_SLOW_MS', 2000),
            'slow_db_total_ms' => (int) env('LOOKOUT_REPORT_GROUPING_DB_TOTAL_MS', 200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto flush on HTTP terminate
    |--------------------------------------------------------------------------
    */
    'auto_flush' => $tracingAutoFlush,

    /*
    |--------------------------------------------------------------------------
    | Performance monitoring (distributed traces / spans)
    |--------------------------------------------------------------------------
    |
    | When enabled, the tracer records spans (HTTP server transaction, DB, outbound
    | HTTP via Guzzle middleware, Artisan, queue, log events) and can POST them with
    | Tracer::flush() / LOOKOUT_TRACING_AUTO_FLUSH.
    |
    | Lookout can reject trace ingest per project (Project settings → Monitoring modes).
    | The API returns HTTP 403 with a JSON message. Use Tracer::flushWithResult() if you
    | want to log that case, and keep LOOKOUT_PERFORMANCE_ENABLED in sync (e.g. from
    | GET /api/v1/projects → performance_ingest_enabled during deploy).
    |
    | Register middleware aliases in order: lookoutTracing.continueTrace, then
    | lookoutTracing.performance. Optional: performance.middleware_auto_register only
    | appends performance to the web and api groups (add continueTrace yourself if missing).
    |
    */
    'performance' => [
        'enabled' => $laravelQuickStart,

        /*
         * When true, log a warning if trace flush returns HTTP 403 (project disabled performance ingest).
         */
        'log_forbidden_trace_ingest' => env('LOOKOUT_LOG_FORBIDDEN_TRACE_INGEST', true),

        'middleware_auto_register' => MonitoringEnv::resolveEnabled(env('LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE'), $laravelQuickStart),

        /*
         * Flush trace to Lookout after each Artisan command or queue job (in addition to HTTP auto_flush).
         */
        'flush_after_cli_and_queue' => env('LOOKOUT_PERFORMANCE_FLUSH_CLI_QUEUE', false),

        /*
         * Queue: merge traceparent + baggage into each payload so workers continue the same trace_id.
         * When queue_publish_span is true, a short queue.publish child is recorded under the current span and
         * the worker’s queue.process transaction parents to that span (sync + async).
         */
        'queue_propagate_trace' => env('LOOKOUT_PERFORMANCE_QUEUE_PROPAGATE_TRACE', true),
        'queue_publish_span' => env('LOOKOUT_PERFORMANCE_QUEUE_PUBLISH_SPAN', true),

        'trace_limits' => [
            'max_spans' => (int) env('LOOKOUT_PERFORMANCE_MAX_SPANS', 512),
            'max_attributes_per_span' => (int) env('LOOKOUT_PERFORMANCE_MAX_ATTRIBUTES_PER_SPAN', 128),
            'max_span_events_per_span' => (int) env('LOOKOUT_PERFORMANCE_MAX_SPAN_EVENTS_PER_SPAN', 128),
            'max_attributes_per_span_event' => (int) env('LOOKOUT_PERFORMANCE_MAX_ATTRIBUTES_PER_SPAN_EVENT', 128),
        ],

        /*
         * Sampler for brand-new traces (no incoming traceparent header). Incoming traces with sampled=0 never record.
         */
        'sampler' => [
            'class' => env('LOOKOUT_PERFORMANCE_SAMPLER', RateSampler::class),
            'config' => [
                'rate' => (float) env('LOOKOUT_PERFORMANCE_SAMPLE_RATE', 0.1),
            ],
        ],

        /*
         * Tail sampling: record spans locally for every trace (when performance is on), then drop the batch at
         * flush unless it is “interesting” or tied to an error report / upstream sample / distributed child.
         * Head sampler still sets the traceparent propagation hint (defaultSampled) when tail sampling is on.
         *
         * LOOKOUT_PERFORMANCE_TAIL_SAMPLING: enable tail policy at export time.
         * LOOKOUT_PERFORMANCE_TAIL_SLOW_MS: root duration threshold (ms) for always_export_slow.
         * LOOKOUT_PERFORMANCE_TAIL_RESIDUAL_RATE: random keep fraction for otherwise boring traces (0 = off).
         */
        'tail_sampling' => [
            'enabled' => env('LOOKOUT_PERFORMANCE_TAIL_SAMPLING', false),
            'slow_transaction_ms' => (int) env('LOOKOUT_PERFORMANCE_TAIL_SLOW_MS', 2000),
            'always_export_slow' => env('LOOKOUT_PERFORMANCE_TAIL_ALWAYS_SLOW', true),
            'http_error_status_from' => (int) env('LOOKOUT_PERFORMANCE_TAIL_HTTP_ERROR_FROM', 500),
            'export_on_span_internal_error' => env('LOOKOUT_PERFORMANCE_TAIL_EXPORT_ON_SPAN_ERROR', true),
            'residual_rate' => (float) env('LOOKOUT_PERFORMANCE_TAIL_RESIDUAL_RATE', 0.0),
            'keep_distributed_participation' => env('LOOKOUT_PERFORMANCE_TAIL_KEEP_DISTRIBUTED', true),
        ],

        /*
         * cache: Laravel cache repository (Redis, Memcached, file, etc.) — hit/miss/set/forget spans, many()
         *   / putMany() batch spans, duration_ms, optional TTL, tag counts; root rollup: hit/miss/set/forget
         *   counts, hit_ratio, batch call counts.
         * redis: raw Redis commands via Laravel — duration, blocking commands (BLPOP, XREAD … BLOCK, …),
         *   blocking_duration_ms, SET NX → cache.lock_attempt.
         * http_client: Guzzle middleware + Laravel Http:: spans (RequestSending / ResponseReceived) + server.address.
         *
         * db.query_count is always set on the transaction root when the database collector is on (including 0).
         * db.total_duration_ms and db.slow_query_count summarize all queries in the transaction (not just sampled spans).
         * repeat_query_max / suspected_n_plus_one require query_insights.enabled and at least one query.
         * php.memory_* uses memory_reset_peak_usage() per HTTP request, queue job, Artisan command, and Octane
         * RequestReceived so peaks reflect the current unit of work in long-lived workers.
         */
        'slow_query_ms' => (float) env('LOOKOUT_PERFORMANCE_SLOW_QUERY_MS', 100),

        'collectors' => [
            'http_server' => env('LOOKOUT_PERFORMANCE_COLLECT_HTTP', true),
            'database' => env('LOOKOUT_PERFORMANCE_COLLECT_DB', true),
            'http_client' => env('LOOKOUT_PERFORMANCE_COLLECT_HTTP_CLIENT', true),
            'cache' => env('LOOKOUT_PERFORMANCE_COLLECT_CACHE', true),
            'redis' => env('LOOKOUT_PERFORMANCE_COLLECT_REDIS', false),
            'console' => env('LOOKOUT_PERFORMANCE_COLLECT_CONSOLE', true),
            'queue' => env('LOOKOUT_PERFORMANCE_COLLECT_QUEUE', true),
            'log' => env('LOOKOUT_PERFORMANCE_COLLECT_LOG', false),
            'view' => env('LOOKOUT_PERFORMANCE_COLLECT_VIEW', false),
        ],

        /*
         * View collector (collectors.view): records one zero-duration view.render span per
         * composing view. High volume — capped per request, and namespaced vendor views
         * (containing "::") are skipped. Add substrings to view_deny to exclude more by name.
         *
         * view_data (opt-in, default off): also capture the actual variable VALUES passed to each
         * view, serialized into a normalized, redacted tree (same engine as dumps — depth/child/
         * string/byte caps, cycle detection, secret redaction by key). Off by default because values
         * can carry PII and inflate trace size; the collector otherwise records variable names only.
         * Keep the serializer caps tight — this runs on every view render.
         */
        'view_max_per_request' => (int) env('LOOKOUT_PERFORMANCE_VIEW_MAX_PER_REQUEST', 50),
        'view_deny' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOOKOUT_PERFORMANCE_VIEW_DENY', ''))))),
        'view_data' => filter_var(env('LOOKOUT_PERFORMANCE_COLLECT_VIEW_DATA', false), FILTER_VALIDATE_BOOLEAN),
        'view_data_serializer' => [
            'max_depth' => (int) env('LOOKOUT_PERFORMANCE_VIEW_DATA_MAX_DEPTH', 4),
            'max_children' => (int) env('LOOKOUT_PERFORMANCE_VIEW_DATA_MAX_CHILDREN', 50),
            'max_string' => (int) env('LOOKOUT_PERFORMANCE_VIEW_DATA_MAX_STRING', 2048),
            'max_total_bytes' => (int) env('LOOKOUT_PERFORMANCE_VIEW_DATA_MAX_TOTAL_BYTES', 16384),
        ],

        /*
         * view_source (opt-in, default off): also capture a capped excerpt of the template's own
         * source (view.source) so the UI can show what the view looks like. The read is cached per
         * path within a request. Off by default — it touches the filesystem on render.
         */
        'view_source' => filter_var(env('LOOKOUT_PERFORMANCE_COLLECT_VIEW_SOURCE', false), FILTER_VALIDATE_BOOLEAN),
        'view_source_max_lines' => (int) env('LOOKOUT_PERFORMANCE_VIEW_SOURCE_MAX_LINES', 60),
        'view_source_max_bytes' => (int) env('LOOKOUT_PERFORMANCE_VIEW_SOURCE_MAX_BYTES', 8192),

        'database_sample_every' => (int) env('LOOKOUT_PERFORMANCE_DB_SAMPLE_EVERY', 1),

        'query_insights' => [
            'enabled' => env('LOOKOUT_PERFORMANCE_QUERY_INSIGHTS', true),
            'n_plus_one_min_repeat' => (int) env('LOOKOUT_PERFORMANCE_N_PLUS_ONE_MIN_REPEAT', 4),
            'n_plus_one_min_queries' => (int) env('LOOKOUT_PERFORMANCE_N_PLUS_ONE_MIN_QUERIES', 8),
        ],

        /*
         * Extra request/response attributes attached to the http.server root span. PII-bearing
         * fields (client_ip, user_id) are opt-in. query_string is truncated to 512 chars; when
         * a route is matched, http.route is recorded for stable per-endpoint grouping. Outbound
         * call rollups (http.client.count / http.client.time_ms) follow the http_client collector.
         */
        'request_metadata' => [
            'query_string' => env('LOOKOUT_PERFORMANCE_CAPTURE_QUERY_STRING', true),
            'user_agent' => env('LOOKOUT_PERFORMANCE_CAPTURE_USER_AGENT', true),
            'response_size' => env('LOOKOUT_PERFORMANCE_CAPTURE_RESPONSE_SIZE', true),
            'client_ip' => env('LOOKOUT_PERFORMANCE_CAPTURE_CLIENT_IP', false),
            'user_id' => env('LOOKOUT_PERFORMANCE_CAPTURE_USER_ID', false),

            /*
             * Authenticated user display name / email (opt-in, PII). When on, the http.server
             * root span carries http.user_name / http.user_email read from the auth user model
             * (name/display_name/username, then email), each truncated to 256 chars. Off by
             * default — only enable where surfacing who made a request is acceptable.
             */
            'user_name' => env('LOOKOUT_PERFORMANCE_CAPTURE_USER_NAME', false),
            'user_email' => env('LOOKOUT_PERFORMANCE_CAPTURE_USER_EMAIL', false),

            /*
             * Low-risk request shape, on by default: HTTP method, request/response content
             * types, and route-model-bound parameters (object params reduced to their route
             * key — never the model). All run through DataRedactor by key name.
             */
            'http_method' => env('LOOKOUT_PERFORMANCE_CAPTURE_HTTP_METHOD', true),
            'content_type' => env('LOOKOUT_PERFORMANCE_CAPTURE_CONTENT_TYPE', true),
            'route_params' => env('LOOKOUT_PERFORMANCE_CAPTURE_ROUTE_PARAMS', true),

            /*
             * PII-bearing payloads, opt-in. Header names and structured (JSON/form) body keys
             * are redacted by DataRedactor (authorization, cookie, token, password, …); bodies
             * are truncated to body_max_bytes. Response bodies are captured for textual/JSON
             * content types only.
             */
            'request_headers' => env('LOOKOUT_PERFORMANCE_CAPTURE_REQUEST_HEADERS', false),
            'response_headers' => env('LOOKOUT_PERFORMANCE_CAPTURE_RESPONSE_HEADERS', false),
            'request_body' => env('LOOKOUT_PERFORMANCE_CAPTURE_REQUEST_BODY', false),
            'response_body' => env('LOOKOUT_PERFORMANCE_CAPTURE_RESPONSE_BODY', false),
            'body_max_bytes' => (int) env('LOOKOUT_PERFORMANCE_CAPTURE_BODY_MAX_BYTES', 8192),
        ],
    ],
];
