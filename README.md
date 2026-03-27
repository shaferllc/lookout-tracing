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
- **`Tracer::configure([...])`** + **`Tracer::flush()`** — send finished spans to `POST /api/ingest/trace` (set `api_key`, `base_uri`, optional `environment` / `release`). Use **`Tracer::flushWithResult()`** (or **`Tracing::flushWithResult()`**) when you need the HTTP **status** (e.g. **403** if the Lookout project disabled trace ingest).

## Error reporting client

Uncaught exceptions use **`Lookout\Tracing\Reporting\ErrorReportClient`**: middleware enriches the payload (Laravel + HTTP context, git metadata, `context.attributes` from **`Lookout\Tracing\Reporting\ReportScope`** and configurable **`AttributeProviderInterface`** classes, optional **`client_solutions`** strings), then **`ReportTruncator`** enforces Lookout size limits, optional **`ReportSampler`** drops a random fraction, and the payload is POSTed immediately or **queued** and flushed on **shutdown** (`reporting.queue` / `reporting.send_immediately`).

Optional **breadcrumb recorders** (same config block as core instrumentation, `instrumentation.enabled` must be true): **cache** hits/misses, **Redis** commands, **views** (view composer `*`), **outbound HTTP** (`Illuminate\Http\Client` events), **response** metadata (`ResponsePrepared`), **`dump()`** via Symfony VarDumper, plus manual **`Lookout\Tracing\GlowBreadcrumb::glow()`** and **`Lookout\Tracing\FilesystemBreadcrumb::record()`**. Env flags: `LOOKOUT_INSTRUMENT_CACHE`, `_REDIS`, `_VIEWS`, `_OUTBOUND_HTTP`, `_RESPONSE_DETAIL`, `_DUMP`.

Global no-op: `LOOKOUT_DISABLED` or `reporting.disabled`. Ingest fields **`is_log`**, **`open_frame_index`**, and **`grouping_override`** (custom fingerprint when `fingerprint` is empty; camelCase aliases **`isLog`**, **`openFrameIndex`**, **`overriddenGrouping`**) are stored on the server. In the Lookout app, **Project → Monitoring modes** can turn off **`POST /api/ingest/trace`** per project while leaving error ingest enabled.

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

### Framework breadcrumbs & exception reporting

The provider registers **event listeners** (when `instrumentation.enabled` is true) that append **breadcrumbs** for:

| Area | Laravel events (indicative) |
|------|----------------------------|
| HTTP | `RouteMatched`, `RequestHandled` |
| Console | `CommandStarting`, `CommandFinished` |
| Queue | `JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred` |
| Optional | `QueryExecuted` (sampled), `MessageLogged`, allowlisted domain events, or a wildcard listener |

Breadcrumbs are **cleared** at each route match, Artisan command, or queue job so `queue:work` and Octane do not mix unrelated requests.

Set **`LOOKOUT_REPORT_EXCEPTIONS=true`** (plus API key and base URI) to register a **`reportable`** handler on the default exception handler. It POSTs to **`POST /api/ingest`** with:

- exception message, class, stack trace, and **stack frames**
- current **breadcrumbs**
- **trace** fields from `Tracer::errorIngestTraceFields()` when a transaction was started
- **`context.laravel`**: framework version, PHP version, route, queue job name, Artisan command, HTTP path/method when available

Tune knobs in `config/lookout-tracing.php` (`instrumentation.*`, `breadcrumbs_max`, `error_ingest_path`).

### Performance monitoring (traces & spans)

Enable with **`LOOKOUT_PERFORMANCE_ENABLED=true`** (and keep `LOOKOUT_API_KEY` / `LOOKOUT_BASE_URI` set). This turns on **sampled span recording**: OpenTelemetry-style **trace ids**, **spans**, and optional **span events**, sent to **`POST /api/ingest/trace`** via `Tracer::flush()` or **`LOOKOUT_TRACING_AUTO_FLUSH=true`**. Ensure the project allows trace ingest in **Lookout → Project settings → Monitoring modes**; otherwise the API returns **403**.

1. **Middleware (order matters):** register **`lookoutTracing.continueTrace`** first, then **`lookoutTracing.performance`**, or set **`LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE=true`** to append only the performance middleware to `web` and `api` (you still add `continueTrace` yourself if it is not already in those groups).
2. **Sampling:** default **`RateSampler`** at **10%** (`LOOKOUT_PERFORMANCE_SAMPLE_RATE=0.1`). Implement `Lookout\Tracing\Performance\Sampler` and set `performance.sampler.class` for custom logic. Traces continued via `sentry-trace` with **`sampled=0`** never record spans (propagation only).
3. **Limits:** `performance.trace_limits` — max spans per export, max attributes per span / span event, max span events per span.
4. **Hooks:** `Tracing::configureSpans(fn (Span $span) => …)` and `Tracing::configureSpanEvents(fn (array $event) => …|null)` — return **`null`** from the span-event callback to drop an event.
5. **Collectors** (`performance.collectors.*`): HTTP server transaction, **database** queries (child `db.query` spans), **console** / **queue** root transactions, **log** lines as span events, and **HTTP client** spans when you attach **`GuzzleTraceMiddleware`** (see below).

CLI / queue: enable **`LOOKOUT_PERFORMANCE_FLUSH_CLI_QUEUE=true`** to flush after each command or job, or call **`Tracing::flush()`** yourself.

### Rails

For Ruby on Rails, use the copy-paste module under **`integrations/rails/`** in the Lookout repository (`lookout_framework.rb` + README): `ActiveSupport::Notifications` for controller and Active Job, optional SQL sampling, and `LookoutFramework.report_exception` from your error pipeline.

## Guzzle 7

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Lookout\Tracing\Http\GuzzleTraceMiddleware;

$stack = HandlerStack::create();
$stack->push(GuzzleTraceMiddleware::create());

$client = new Client(['handler' => $stack]);
```

With **performance monitoring** enabled, the same middleware also records **`http.client`** child spans (when a parent span is active and sampling allows recording).

## Requirements

- PHP 8.3+
- `psr/http-message` (for the optional Guzzle middleware type hints)
- `guzzlehttp/guzzle` (optional, for `GuzzleTraceMiddleware` + promises)

## SDK roadmap & Lookout alignment

The Lookout app surfaces **Traces**, **Transactions**, and **trace detail** in the web UI; the SDK sends **errors** (`POST /api/ingest`) and, when enabled, **spans** (`POST /api/ingest/trace`) with consistent **`trace_id`** / **`sentry-trace`** propagation.

| Server behavior | SDK support |
|-----------------|-------------|
| **`performance_ingest_enabled`** false | Trace ingest returns **403**. Laravel: enable **`performance.sync_from_api`** (Sanctum token + project id) so **`Tracer::isPerformanceEnabled()`** matches the server on boot; or set **`LOOKOUT_PERFORMANCE_ENABLED=false`**. Auto-flush and queue/cli flush log **`lookout.tracing.trace_forbidden`** when **`performance.log_forbidden_trace_ingest`** is true (default). |
| **`GET /api/v1/projects/{id}`** | **`LookoutManagementApi::fetchProject()`** + sync config (see `lookout-tracing.php` **`performance.sync_from_api`**). |
| **429 / flaky network** | **`trace_ingest.max_attempts`**, **`retry_delay_ms`**, **`retry_statuses`** (env: **`LOOKOUT_TRACE_INGEST_*`**) — **`Tracer::flushWithResult()`** uses **`HttpTransport::postJsonWithResponseRetries()`**. **403** is never retried. |

### Implemented building blocks

- **`Lookout\Tracing\Interop\OpenTelemetryTraceConverter::toLookoutIngestBody()`** — OTLP-style JSON (`resourceSpans` / `scopeSpans` / `spans`) → Lookout trace ingest payload (no OpenTelemetry PHP SDK required).
- **`Lookout\Tracing\Http\ContinueTracePsr15Middleware`** — PSR-15 **`sentry-trace`** / **`baggage`** parsing (Slim, Mezzio, etc.).
- **`Lookout\Tracing\Support\DataRedactor::redact()`** — recursive redaction for span **`data`** / context-style arrays.
- **`Lookout\Tracing\Testing\TracerInspection::traceIngestBody()`** — stable access to **`buildTraceIngestBody()`** in tests.

### Still optional / app-specific

- Full **OpenTelemetry PHP SDK** adapter (protobuf / exporter pipeline).
- **PSR-15 “performance”** middleware (auto HTTP transactions) — today use manual **`Tracing::startTransaction`** or stay on Laravel.
- **Queue-based async flush** with deduplication across workers.

## Scope

**Tracing** supports **manual** transactions/spans (`Tracing::trace()`, `startTransaction`) and optional **performance mode**: sampled **auto spans** for HTTP (middleware), SQL, Artisan, queue, logs, and outbound Guzzle calls, flushed to Lookout’s trace ingest.

**Framework instrumentation** (above) still records **breadcrumbs** for **error reports**; performance collectors add **span trees** for the distributed trace UI when you flush to **`/api/ingest/trace`**.

**Crons:** Lookout stores check-ins and monitor metadata; it does **not** yet auto-open issues or email you on missed schedules like Sentry’s hosted monitors—you can build alerting on top (e.g. scheduled jobs reading the API) or extend the app later.
