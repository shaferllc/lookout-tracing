<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Lookout API key
    |--------------------------------------------------------------------------
    |
    | Required for Tracer::flush() to POST spans. Same key as error ingest (X-Api-Key).
    |
    */
    'api_key' => env('LOOKOUT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Lookout base URL
    |--------------------------------------------------------------------------
    |
    | Scheme + host + optional port, no trailing slash. Flush uses base + ingest_trace_path.
    |
    */
    'base_uri' => env('LOOKOUT_BASE_URI', env('APP_URL')),

    'ingest_trace_path' => '/api/ingest/trace',

    'cron_ingest_path' => '/api/ingest/cron',

    'profile_ingest_path' => '/api/ingest/profile',

    'environment' => env('APP_ENV'),

    'release' => env('LOOKOUT_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Auto flush on HTTP terminate
    |--------------------------------------------------------------------------
    |
    | When true, registered spans are sent at the end of each request via app terminating callback.
    |
    */
    'auto_flush' => env('LOOKOUT_TRACING_AUTO_FLUSH', false),
];
