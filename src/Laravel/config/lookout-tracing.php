<?php

declare(strict_types=1);

return [
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto flush on HTTP terminate
    |--------------------------------------------------------------------------
    */
    'auto_flush' => env('LOOKOUT_TRACING_AUTO_FLUSH', false),
];
