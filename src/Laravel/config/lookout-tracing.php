<?php

declare(strict_types=1);

use Lookout\Tracing\Performance\RateSampler;

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
    | Required for Tracer::flush(), cron/profile clients, error reporting, and instrumentation HTTP calls.
    |
    */
    'api_key' => env('LOOKOUT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Lookout base URL
    |--------------------------------------------------------------------------
    |
    | Scheme + host + optional port, no trailing slash.
    |
    */
    'base_uri' => env('LOOKOUT_BASE_URI', env('APP_URL')),

    'ingest_trace_path' => '/api/ingest/trace',

    'cron_ingest_path' => '/api/ingest/cron',

    'profile_ingest_path' => '/api/ingest/profile',

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
    'report_exceptions' => env('LOOKOUT_REPORT_EXCEPTIONS', false),

    'error_ingest_path' => env('LOOKOUT_ERROR_INGEST_PATH', '/api/ingest'),

    'environment' => env('APP_ENV'),

    'release' => env('LOOKOUT_RELEASE'),

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

        'http' => env('LOOKOUT_INSTRUMENT_HTTP', true),
        'console' => env('LOOKOUT_INSTRUMENT_CONSOLE', true),
        'queue' => env('LOOKOUT_INSTRUMENT_QUEUE', true),

        /*
         * SQL breadcrumbs (can be noisy). database_sample_every: 1 = every query, 5 = every 5th.
         */
        'database' => env('LOOKOUT_INSTRUMENT_DATABASE', false),
        'database_sample_every' => (int) env('LOOKOUT_INSTRUMENT_DATABASE_SAMPLE_EVERY', 5),

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
        'cache' => env('LOOKOUT_INSTRUMENT_CACHE', false),
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

        'truncation' => [
            'max_message_length' => (int) env('LOOKOUT_REPORT_MAX_MESSAGE', 131_072),
            'max_stack_trace_bytes' => (int) env('LOOKOUT_REPORT_MAX_STACK', 524_288),
            'max_stack_frames' => (int) env('LOOKOUT_REPORT_MAX_FRAMES', 200),
            'max_breadcrumbs' => (int) env('LOOKOUT_REPORT_MAX_BREADCRUMBS', 50),
            'max_breadcrumb_message' => (int) env('LOOKOUT_REPORT_MAX_CRUMB_MSG', 2000),
            'max_breadcrumb_data_json' => (int) env('LOOKOUT_REPORT_MAX_CRUMB_DATA', 8192),
            'max_context_json' => (int) env('LOOKOUT_REPORT_MAX_CONTEXT', 262_144),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto flush on HTTP terminate
    |--------------------------------------------------------------------------
    */
    'auto_flush' => env('LOOKOUT_TRACING_AUTO_FLUSH', false),

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
        'enabled' => env('LOOKOUT_PERFORMANCE_ENABLED', false),

        /*
         * When true, log a warning if trace flush returns HTTP 403 (project disabled performance ingest).
         */
        'log_forbidden_trace_ingest' => env('LOOKOUT_LOG_FORBIDDEN_TRACE_INGEST', true),

        /*
         * Optional: on each boot, GET /api/v1/projects/{id} with a Sanctum bearer token and override
         * the tracer’s performance_enabled flag from performance_ingest_enabled (so LOOKOUT_PERFORMANCE_ENABLED
         * can stay true in .env while the server gate turns recording off without 403 spam).
         */
        'sync_from_api' => [
            'enabled' => env('LOOKOUT_PERFORMANCE_SYNC_FROM_API', false),
            'bearer_token' => env('LOOKOUT_SYNC_API_TOKEN'),
            'project_id' => env('LOOKOUT_SYNC_PROJECT_ID'),
        ],

        'middleware_auto_register' => env('LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE', false),

        /*
         * Flush trace to Lookout after each Artisan command or queue job (in addition to HTTP auto_flush).
         */
        'flush_after_cli_and_queue' => env('LOOKOUT_PERFORMANCE_FLUSH_CLI_QUEUE', false),

        'trace_limits' => [
            'max_spans' => (int) env('LOOKOUT_PERFORMANCE_MAX_SPANS', 512),
            'max_attributes_per_span' => (int) env('LOOKOUT_PERFORMANCE_MAX_ATTRIBUTES_PER_SPAN', 128),
            'max_span_events_per_span' => (int) env('LOOKOUT_PERFORMANCE_MAX_SPAN_EVENTS_PER_SPAN', 128),
            'max_attributes_per_span_event' => (int) env('LOOKOUT_PERFORMANCE_MAX_ATTRIBUTES_PER_SPAN_EVENT', 128),
        ],

        /*
         * Sampler for brand-new traces (no sentry-trace header). Incoming traces with sampled=0 never record.
         */
        'sampler' => [
            'class' => env('LOOKOUT_PERFORMANCE_SAMPLER', RateSampler::class),
            'config' => [
                'rate' => (float) env('LOOKOUT_PERFORMANCE_SAMPLE_RATE', 0.1),
            ],
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
         * repeat_query_max / suspected_n_plus_one require query_insights.enabled and at least one query.
         * php.memory_* uses memory_reset_peak_usage() per HTTP request, queue job, Artisan command, and Octane
         * RequestReceived so peaks reflect the current unit of work in long-lived workers.
         */
        'collectors' => [
            'http_server' => env('LOOKOUT_PERFORMANCE_COLLECT_HTTP', true),
            'database' => env('LOOKOUT_PERFORMANCE_COLLECT_DB', true),
            'http_client' => env('LOOKOUT_PERFORMANCE_COLLECT_HTTP_CLIENT', true),
            'cache' => env('LOOKOUT_PERFORMANCE_COLLECT_CACHE', false),
            'redis' => env('LOOKOUT_PERFORMANCE_COLLECT_REDIS', false),
            'console' => env('LOOKOUT_PERFORMANCE_COLLECT_CONSOLE', true),
            'queue' => env('LOOKOUT_PERFORMANCE_COLLECT_QUEUE', true),
            'log' => env('LOOKOUT_PERFORMANCE_COLLECT_LOG', false),
            'view' => env('LOOKOUT_PERFORMANCE_COLLECT_VIEW', false),
        ],

        'database_sample_every' => (int) env('LOOKOUT_PERFORMANCE_DB_SAMPLE_EVERY', 1),

        'query_insights' => [
            'enabled' => env('LOOKOUT_PERFORMANCE_QUERY_INSIGHTS', true),
            'n_plus_one_min_repeat' => (int) env('LOOKOUT_PERFORMANCE_N_PLUS_ONE_MIN_REPEAT', 4),
            'n_plus_one_min_queries' => (int) env('LOOKOUT_PERFORMANCE_N_PLUS_ONE_MIN_QUERIES', 8),
        ],
    ],
];
