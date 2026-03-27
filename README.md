# lookout/tracing

PHP library for **Lookout** distributed tracing with **Sentry-compatible** headers and manual instrumentation. You do **not** need the Sentry SDK; APIs mirror [Sentry PHP tracing instrumentation](https://docs.sentry.io/platforms/php/tracing/instrumentation/) and [trace propagation](https://docs.sentry.io/platforms/php/tracing/trace-propagation/) so existing patterns transfer easily.

## Install

```bash
composer require lookout/tracing
```

(This repository vendors the package from `packages/lookout-tracing` via a Composer path repository.)

## Propagation

- **Incoming:** parse `sentry-trace` and `baggage` (e.g. from `PSR-7` request headers or Laravel’s `Request`).
- **Outgoing:** add the same headers to downstream HTTP calls so other services can continue the trace.

```php
use Lookout\Tracing\Tracer;

Tracer::instance()->continueTrace(
    $request->getHeaderLine('sentry-trace'),
    $request->getHeaderLine('baggage'),
);

$headers = Tracer::instance()->outgoingTraceHeaders();
// [ 'sentry-trace' => '...', 'baggage' => '...' ]
```

HTML meta tags for browser SDKs:

```php
use Lookout\Tracing\HtmlTraceMeta;

echo HtmlTraceMeta::render();
```

## Custom instrumentation

```php
use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Tracing;

$tx = Tracing::startTransaction('GET /orders', SpanOperation::HTTP_SERVER);

Tracing::trace(function () {
    // …
}, SpanOperation::HTTP_CLIENT, 'GET https://api.example.com/v1/orders');

$tx->finish();
```

Common `op` values are defined on `Lookout\Tracing\SpanOperation` (`http.server`, `http.client`, `db.query`, `cache.get`, `queue.publish`, etc.).

## Lookout ingest

- **`Tracer::errorIngestTraceFields()`** — `trace_id`, `span_id`, `parent_span_id`, `transaction` for your error JSON body to `POST /api/ingest`.
- **`Tracer::configure([...])`** + **`Tracer::flush()`** — send finished spans to `POST /api/ingest/trace` (set `api_key`, `base_uri`, optional `environment` / `release`).

## Cron monitors (Sentry Crons–style)

Aligned with [Sentry PHP Crons](https://docs.sentry.io/platforms/php/crons/): `in_progress` → `ok` / `error`, optional heartbeat, and monitor upsert via `monitor_config`.

```php
use Lookout\Tracing\Cron\CheckInStatus;
use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Cron\MonitorConfig;
use Lookout\Tracing\Cron\MonitorSchedule;

CronClient::configure([
    'api_key' => getenv('LOOKOUT_API_KEY'),
    'base_uri' => 'https://your-lookout-host.example',
    'cron_ingest_path' => '/api/ingest/cron',
]);

$config = MonitorConfig::make(MonitorSchedule::crontab('0 * * * *'), checkinMarginMinutes: 5);

$id = CronClient::captureCheckIn('hourly-job', CheckInStatus::inProgress(), monitorConfig: $config);
CronClient::captureCheckIn('hourly-job', CheckInStatus::ok(), $id);

CronClient::withMonitor('wrapped-job', fn () => doWork(), $config);

CronClient::captureCheckIn('heartbeat', CheckInStatus::ok(), null, 12.0);
```

Laravel: the same service provider configures `CronClient` from `config/lookout-tracing.php` (`cron_ingest_path` defaults to `/api/ingest/cron`).

## Profiling (CPU / flame graphs)

Aligned with [Sentry PHP profiling](https://docs.sentry.io/platforms/php/profiling/) in spirit: capture with **Excimer** (speedscope JSON), **xhprof** / **Tideways**, **SPX**, or cooperative **`php.manual_pulse`** sampling (no extension), then POST to Lookout.

```php
use Lookout\Tracing\Profiling\ProfileClient;

ProfileClient::configure([
    'api_key' => getenv('LOOKOUT_API_KEY'),
    'base_uri' => 'https://your-lookout-host.example',
    'profile_ingest_path' => '/api/ingest/profile',
]);

ProfileClient::sendProfile([
    'agent' => 'other',
    'format' => 'speedscope',
    'data' => [/* speedscope JSON object */],
    'trace_id' => 'abc123…',
    'transaction' => 'GET /checkout',
]);
```

Package classes under `Lookout\Tracing\Profiling\` (e.g. `ExcimerExporter`, `XhprofLikeExporter`, `SpxPayload`, `ManualPulseSampler`) help build `agent` / `format` / `data` for each backend. Laravel: `LookoutTracingServiceProvider` merges the same `api_key`, `base_uri`, and `profile_ingest_path` from `config/lookout-tracing.php`.

## Laravel

Auto-discovery registers `Lookout\Tracing\Laravel\LookoutTracingServiceProvider`.

- Middleware alias: **`lookoutTracing.continueTrace`** — call `continueTrace()` from incoming headers.
- Publish config: `php artisan vendor:publish --tag=lookout-tracing-config`
- Env: `LOOKOUT_API_KEY`, `LOOKOUT_BASE_URI` (or `APP_URL`), optional `LOOKOUT_TRACING_AUTO_FLUSH=true`. Profile ingest path defaults to `/api/ingest/profile` (override in published config).

## Guzzle 7

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Lookout\Tracing\Http\GuzzleTraceMiddleware;

$stack = HandlerStack::create();
$stack->push(GuzzleTraceMiddleware::create());

$client = new Client(['handler' => $stack]);
```

## Requirements

- PHP 8.3+
- `psr/http-message` (for the optional Guzzle middleware type hints)

## Scope

This library covers **manual** transactions/spans and **header propagation** in the spirit of Sentry’s PHP docs. It does **not** auto-wrap Laravel’s DB layer, HTTP client, queues, or cache the way `sentry/sentry-laravel` does; add `Tracing::trace()` (or your own listeners) around the work you want recorded, or extend the package in your app.

**Crons:** Lookout stores check-ins and monitor metadata; it does **not** yet auto-open issues or email you on missed schedules like Sentry’s hosted monitors—you can build alerting on top (e.g. scheduled jobs reading the API) or extend the app later.
