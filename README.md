# lookout/tracing

PHP library for **Lookout** distributed tracing: compact **traceparent**-style propagation, **W3C baggage**, manual transactions/spans, and optional Laravel integration. Wire formats stay compatible with common PHP tracing clients without naming third-party vendors here.

## Install

Requirements: **PHP 8.3+** and **Composer**. You always add the library with Composer; only Laravel gets auto-wiring via a service provider.

### Laravel application

Run these from your **Laravel project root** (the directory that contains `artisan` and `composer.json`):

1. **Add the package**
   ```bash
   cd /path/to/your-laravel-app
   composer require lookout/tracing
   ```
   Composer updates `composer.json` / `composer.lock` and downloads `lookout/tracing` into `vendor/`.

2. **Configure Lookout (pick one path)**
   - **Interactive (recommended):** `php artisan lookout:install` — choose **Create a new project** (Lookout web URL + your **API token** from Profile → **API tokens**) or **Use an existing DSN**. Create flow calls Lookout’s API (`GET /api/v1/me`, `POST /api/v1/projects`), then writes **`LOOKOUT_DSN`** using the new project’s ingest key. It also appends `LOOKOUT_LARAVEL=true` to `.env`.
   - **Manual:** add to `.env` yourself (see [Quick install](#quick-install) under **Laravel** below), or set `LOOKOUT_API_KEY` + `LOOKOUT_URL` if your team shares one Lookout host.

3. **Clear config cache** (if you use it in this environment)
   ```bash
   php artisan config:clear
   ```

4. **Optional — publish config** when you need every env knob (sampling, middleware, log/metrics toggles):
   ```bash
   php artisan vendor:publish --tag=lookout-tracing-config
   ```

5. **Tracing middleware** — for distributed traces, register `lookoutTracing.continueTrace` (and optionally `lookoutTracing.performance`) on your `web` / `api` stacks, or use `LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE=true` for the performance middleware only (see [Performance monitoring](#performance-monitoring-traces--spans)).

Laravel **auto-discovers** `Lookout\Tracing\Laravel\LookoutTracingServiceProvider`; you do not add it to `config/app.php` manually.

### Other PHP projects (Symfony, Slim, custom apps, libraries)

There is **no** Laravel service provider. Install the same Composer package and **configure the tracer in your bootstrap** (or a DI container):

```bash
cd /path/to/your-php-project
composer require lookout/tracing
```

Then wire **Lookout ingest** explicitly, for example:

```php
use Lookout\Tracing\Tracer;

Tracer::instance()->configure([
    'api_key' => getenv('LOOKOUT_API_KEY') ?: null,
    'base_uri' => rtrim((string) getenv('LOOKOUT_URL'), '/') ?: null,
    'environment' => getenv('APP_ENV') ?: null,
]);
```

Use **`Tracer`**, **`Tracing`**, **`lookout_logger()`**, **`lookout_metrics()`**, PSR-15 middleware (e.g. `ContinueTracePsr15Middleware`), and **`GuzzleTraceMiddleware`** as needed; see **Propagation**, **Custom instrumentation**, **Lookout ingest**, and **Guzzle 7** below. For Slim / Mezzio, see the `slim/slim` suggestion in `composer.json`.

(This monorepo vendors the package from `packages/lookout-tracing` via a Composer path repository when developing Lookout itself.)

## Propagation

- **Incoming:** parse the compact trace header (see `Lookout\Tracing\TraceWireHeaders::HTTP_TRACEPARENT`) and `baggage` (e.g. from `PSR-7` request headers or Laravel’s `Request`).
- **Outgoing:** add the same headers to downstream HTTP calls so other services can continue the trace.

```php
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;

Tracer::instance()->continueTrace(
    $request->getHeaderLine(TraceWireHeaders::HTTP_TRACEPARENT),
    $request->getHeaderLine(TraceWireHeaders::HTTP_BAGGAGE),
);

$headers = Tracer::instance()->outgoingTraceHeaders();
// keys: TraceWireHeaders::HTTP_TRACEPARENT, TraceWireHeaders::HTTP_BAGGAGE
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
- **`Tracer::errorIngestPerformanceGroupingHints()`** — when **`reporting.performance_grouping.enabled`** is true (env **`LOOKOUT_REPORT_PERFORMANCE_GROUPING`**) and **`performance_enabled`** recorded spans in the same request, may add **`grouping_slow_path`** and **`grouping_db_time_ms`** so Lookout can fingerprint slow / DB-heavy errors separately (see Lookout ingest docs).
- **`Tracer::configure([...])`** + **`Tracer::flush()`** — send finished spans to `POST /api/ingest/trace` (set `api_key`, `base_uri`, optional `environment` / `release`). Use **`Tracer::flushWithResult()`** (or **`Tracing::flushWithResult()`**) when you need the HTTP **status** (e.g. **403** if the Lookout project disabled trace ingest).

## Structured logs

**`lookout_logger()->info('User %s logged in', ['alice'])`**, optional **`flush()`**, and a **Monolog** handler. Rows go to **`POST /api/ingest/log`** with the same **`api_key`** / **`base_uri`** as tracing; enable with **`LOOKOUT_LOGS_ENABLED=true`** (Laravel: `config/lookout-tracing.php` → **`logging.enabled`**). Laravel registers a **terminating** flush when **`logging.enabled`** and **`logging.flush_on_terminate`** are true. Long workers should call **`lookout_logger()->flush()`** on a timer or after batches.

```php
lookout_logger()->info('order placed', null, ['order_id' => '42']);
lookout_logger()->flush();
```

```php
use Lookout\Tracing\Logging\Monolog\LookoutMonologHandler;
use Monolog\Logger;

$log = new Logger('app');
$log->pushHandler(new LookoutMonologHandler());
```

## Custom metrics

**`lookout_metrics()->count('orders.completed', 1)`**, **`gauge()`**, **`distribution()`**, optional **`MetricUnit`**, and **`flush()`**. Samples go to **`POST /api/ingest/metric`**; the active **`trace_id`** is attached when a transaction is in flight so the Lookout UI can correlate rollups with traces. Enable with **`LOOKOUT_METRICS_ENABLED=true`** (Laravel: **`metrics.enabled`**). Laravel flushes on **terminating** when **`metrics.enabled`** and **`metrics.flush_on_terminate`** are true.

Optional **`MetricsIngestClient::configure(['before_send_metric' => fn (array $row): ?array => $row])`** drops or mutates rows before enqueue (return **`null`** to skip).

```php
use Lookout\Tracing\Metrics\MetricUnit;

lookout_metrics()->count('button.click', 5, ['plan' => 'pro']);
lookout_metrics()->distribution('page.load_ms', 42.5, ['route' => '/checkout'], MetricUnit::millisecond());
lookout_metrics()->flush();
```

### Real User Monitoring (browser)

Optional **Web Vitals** + **SPA / Livewire** navigation beacons: `POST /api/ingest/rum` (same project API key; **performance ingest** must be enabled on the project). Vanilla script with no npm dependencies:

- **`resources/rum/lookout-rum.js`** — `LookoutRum.init({ endpoint, apiKey, livewireNavigate: true, traceId: () => … })`. Puts **`api_key` in the JSON body** so **`navigator.sendBeacon`** works without custom headers. Correlate with server traces via **`trace_id`** (32 hex), e.g. from **`HtmlTraceMeta`** / a `<meta name="lookout-trace-id">` you render from `Tracer::instance()->traceId` on the server.

## Error reporting client

Uncaught exceptions use **`Lookout\Tracing\Reporting\ErrorReportClient`**: middleware enriches the payload (Laravel + HTTP context, git metadata, `context.attributes` from **`Lookout\Tracing\Reporting\ReportScope`** and configurable **`AttributeProviderInterface`** classes, optional **`client_solutions`** strings), then **`ReportTruncator`** enforces Lookout size limits, optional **`ReportSampler`** drops a random fraction, and the payload is POSTed immediately or **queued** and flushed on **shutdown** (`reporting.queue` / `reporting.send_immediately`).

### Glows (Flare-style manual breadcrumbs)

Similar in spirit to [Flare Laravel glows](https://flareapp.io/docs/laravel/data-collection/glows): **custom timeline notes** that appear with other **breadcrumbs** on the error in Lookout (chronological “what ran before this failed”).

```php
use Lookout\Tracing\GlowBreadcrumb;

GlowBreadcrumb::glow('Payment branch: validated wallet', 'info', ['wallet_id' => $id]);
GlowBreadcrumb::glow('Skipping cache (feature flag)', 'debug');
```

- **`$message`** — required; trimmed, max length enforced with other breadcrumbs.
- **`$level`** — string such as `debug`, `info`, `warning`, `error` (default `info`).
- **`$data`** — optional associative array (subject to the same redaction as other breadcrumb payloads).

Internally these are breadcrumbs with **`type`** `glow` and **`category`** `glow`. They are **not** the Spatie **`Flare::glow()`** API—there is no drop-in facade. They attach to the **error ingest** breadcrumb list, not as separate **span events** on traces (Flare also shows glows on spans in performance; Lookout’s buffer is scoped to the next error report).

### Manual filesystem breadcrumbs

For disk I/O there is no universal Laravel hook; use **`FilesystemBreadcrumb::record()`**:

```php
use Lookout\Tracing\FilesystemBreadcrumb;

FilesystemBreadcrumb::record('read', '/var/app/config.json', 'info', ['bytes' => 1024]);
```

Optional **breadcrumb recorders** (same config block as core instrumentation, `instrumentation.enabled` must be true): **cache** hits/misses, **Redis** commands, **views** (view composer `*`), **outbound HTTP** (`Illuminate\Http\Client` events), **response** metadata (`ResponsePrepared`), **database transactions** (`TransactionBeginning` / `Committed` / `RolledBack`), **`dump()`** via Symfony VarDumper, plus manual **`Lookout\Tracing\GlowBreadcrumb::glow()`** and **`Lookout\Tracing\FilesystemBreadcrumb::record()`**. Env flags: `LOOKOUT_INSTRUMENT_CACHE`, `_REDIS`, `_VIEWS`, `_OUTBOUND_HTTP`, `_RESPONSE_DETAIL`, `_DATABASE_TRANSACTIONS`, `_DUMP`. Set **`LOOKOUT_INSTRUMENT_COMPREHENSIVE_COLLECTION=true`** to turn on the optional recorders above (plus SQL breadcrumbs and performance collectors for cache, Redis, views, log) in one step.

**Broad Laravel error context (what maps where)**

| Area | Lookout |
|------|---------|
| Application info | `context.laravel`: framework + PHP version, **application name**, **locale**, **config cached**, **debug**, **application_env** (`APP_ENV`), route/command/queue hints |
| Laravel context | Same `context.laravel` + **`context.log_context`** from `context()` / `Illuminate\Log\Context\Repository` |
| Exception context | **`context.exception_context`** when the throwable implements **`context()`** (redacted) |
| Stacktrace arguments | Structured **`stack_frames[].args`** when `reporting.include_stack_arguments` is true and PHP supplies trace args (`zend.exception_ignore_args=0`) |
| Requests / URL / user | `url`, `user`, `issue_route`, `context.server`; HTTP breadcrumbs |
| Server info | `context.server` (hostname, SAPI, OS, pid, limits, tz) + request `SERVER_ADDR` when present |
| Git information | Default **`GitInformationMiddleware`** (commit, etc.) |
| Solutions | **`SolutionsMiddleware`** + `reporting.client_solutions` |
| Console commands | Breadcrumbs + performance spans when enabled |
| Jobs and queues | Breadcrumbs + queue trace propagation + performance |
| Queries | Optional SQL breadcrumbs; **DB spans** + query insights when performance DB collector on |
| Database transactions | Breadcrumbs when `instrumentation.database_transactions` or `comprehensive_collection` |
| Cache events | Breadcrumbs + optional cache **spans** |
| Redis commands | Breadcrumbs + optional Redis **spans** |
| External HTTP | Breadcrumbs + **http.client** spans (Guzzle / `Http::`) |
| Views | View composer breadcrumbs + optional view **spans** |
| Logs | Optional `MessageLogged` breadcrumbs; optional log **spans**; structured **`/api/ingest/log`** via `lookout_logger()` |
| Livewire | **`context.livewire`** (component class + name) on Livewire requests |
| Spans / errors when tracing | **`LOOKOUT_PERFORMANCE_ENABLED`**, `Tracer::markTraceMustExport` on error reports |
| Dumps | `instrumentation.dump` → **`DumpInstrumentation`** |
| Glows / filesystem | Manual **`GlowBreadcrumb::glow()`**, **`FilesystemBreadcrumb::record()`** |
| Customise report | **`reporting.middleware`**, **`AttributeProviderInterface`**, **`ReportScope`** |

Global no-op: `LOOKOUT_DISABLED` or `reporting.disabled`. Ingest fields **`is_log`**, **`open_frame_index`**, and **`grouping_override`** (custom fingerprint when `fingerprint` is empty; camelCase aliases **`isLog`**, **`openFrameIndex`**, **`overriddenGrouping`**) are stored on the server. In the Lookout app, **Project → Monitoring modes** can turn off **`POST /api/ingest/trace`** and **`POST /api/ingest/rum`** per project while leaving error ingest enabled.

### User feedback (crash page)

When **`ErrorReportClient`** builds an error payload it ensures an **`occurrence_uuid`** (v4) and remembers it for **`lookout_last_error_occurrence_uuid()`** / **`ErrorReportClient::lastOccurrenceUuid()`**. On your custom error view, POST that UUID with the user’s message to **`POST /api/ingest/feedback`** (same project **`api_key`**; see Lookout **Ingest API → User feedback**). The comment appears on that occurrence’s thread in the app. Alternatively use the ingest response / read API **`event_id`** (ULID) as **`event_id`** in the feedback body.

## Cron monitors

Monitor check-ins: `in_progress` → `ok` / `error`, optional heartbeat, and monitor upsert via `monitor_config`.

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

Optional **`meta`** (string/number/bool values, size-limited server-side) on `captureCheckIn` attaches context to the check-in row and merges on completion.

Laravel: the same service provider configures `CronClient` from `config/lookout-tracing.php` (`cron_ingest_path` defaults to `/api/ingest/cron`).

## Profiling (CPU / flame graphs)

Capture with **Excimer** (speedscope JSON), **xhprof** / **Tideways**, **SPX**, or cooperative **`php.manual_pulse`** sampling (no extension), then POST to Lookout.

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

**First-party aggregate hotspots** (`lookout.v1`):

```php
use Lookout\Tracing\Profiling\LookoutProfileV1Payload;
use Lookout\Tracing\Profiling\ProfileClient;

ProfileClient::sendProfile(LookoutProfileV1Payload::aggregateIngestBody(
    [
        ['file' => 'app/Services/Checkout.php', 'line' => 120, 'samples' => 48],
    ],
    meta: ['source' => 'custom-collector'],
    context: ['trace_id' => 'abc123…', 'transaction' => 'POST /checkout'],
));
```

Package classes under `Lookout\Tracing\Profiling\` (e.g. `ExcimerExporter`, `XhprofLikeExporter`, `SpxPayload`, `ManualPulseSampler`, `LookoutProfileV1Payload`) help build `agent` / `format` / `data` for each backend. Laravel: `LookoutTracingServiceProvider` merges the same `api_key`, `base_uri`, and `profile_ingest_path` from `config/lookout-tracing.php`.

**Overhead:** Lookout does not sample profiles for you — wrap `ProfileClient::sendProfile()` (or your Excimer/Tideways hooks) so production only uploads a small fraction of requests or when duration exceeds a threshold, similar to `profiles_sample_rate` / slow-transaction rules elsewhere.

## Laravel

Auto-discovery registers `Lookout\Tracing\Laravel\LookoutTracingServiceProvider`.

### Quick install

```bash
composer require lookout/tracing
php artisan lookout:install
```

`lookout:install` can either **create a project** on your Lookout instance (API token from Profile → API tokens + base URL) or **use an existing DSN**. Either way it appends to `.env`:

```dotenv
LOOKOUT_DSN="https://YOUR_PROJECT_API_KEY@your-lookout-host.example.com"
LOOKOUT_LARAVEL=true
```

- **`LOOKOUT_DSN`** — single line: `https://` + **project ingest API key** as the URL user + `@` + Lookout host (optional port). Percent-encode the key if it contains `@` or other reserved characters. The create-project flow obtains this key from the API after `POST /api/v1/projects`.
- **`LOOKOUT_LARAVEL=true`** — enables **uncaught exception reporting** (`LOOKOUT_REPORT_EXCEPTIONS`) and **trace auto-flush on HTTP terminate** (`LOOKOUT_TRACING_AUTO_FLUSH`) unless you override those env vars explicitly.

Non-interactive:

- Existing project: `php artisan lookout:install --dsn="https://PROJECT_KEY@host.example.com"`.
- New project: `php artisan lookout:install --url="https://host.example.com" --token="your_api_token"` (and **`--organization=ULID`** if your account has more than one organization). Optional **`--project-name="My App"`**. On the same Laravel host (e.g. this Lookout app), you can omit **`--url`** when **`APP_URL`** is set: `--token="…"` alone uses that origin.

Pass **`--no-quick`** to skip `LOOKOUT_LARAVEL=true`.

**API key only (team shares one Lookout URL):** set a default host once — `LOOKOUT_URL`, `LOOKOUT_BASE_URI`, or `config/services.php` → **`lookout.url`** — then each environment only needs **`LOOKOUT_API_KEY`**.

- Middleware alias: **`lookoutTracing.continueTrace`** — call `continueTrace()` from incoming headers.
- Publish config: `php artisan vendor:publish --tag=lookout-tracing-config`
- Env resolution order for **base URI**: `LOOKOUT_DSN` host → `LOOKOUT_BASE_URI` → `LOOKOUT_URL` → `config('services.lookout.url')` → `APP_URL`. Profile ingest path defaults to `/api/ingest/profile` (override in published config).

### Framework breadcrumbs & exception reporting

The provider registers **event listeners** (when `instrumentation.enabled` is true) that append **breadcrumbs** for:

| Area | Laravel events (indicative) |
|------|----------------------------|
| HTTP | `RouteMatched`, `RequestHandled` |
| Console | `CommandStarting`, `CommandFinished` |
| Queue | `JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred` |
| Optional | `QueryExecuted` (sampled), `MessageLogged`, allowlisted domain events, or a wildcard listener |

Breadcrumbs are **cleared** at each route match, Artisan command, or queue job so `queue:work` and Octane do not mix unrelated requests.

With **`LOOKOUT_LARAVEL=true`** or **`LOOKOUT_REPORT_EXCEPTIONS=true`** (and a resolved API key + base URI), the provider registers a **`reportable`** handler on the default exception handler. It POSTs to **`POST /api/ingest`** with:

- exception message, class, stack trace, and **stack frames**
- current **breadcrumbs**
- **trace** fields from `Tracer::errorIngestTraceFields()` when a transaction was started
- **`context.laravel`**: framework version, PHP version, route, queue job name, Artisan command, HTTP path/method when available

Tune knobs in `config/lookout-tracing.php` (`instrumentation.*`, `breadcrumbs_max`, `error_ingest_path`).

### Performance monitoring (traces & spans)

Enable with **`LOOKOUT_PERFORMANCE_ENABLED=true`** (with a resolved API key and base URI from **`LOOKOUT_DSN`**, **`LOOKOUT_API_KEY`** + **`LOOKOUT_URL`**, etc.). This turns on **sampled span recording**: OpenTelemetry-style **trace ids**, **spans**, and optional **span events**, sent to **`POST /api/ingest/trace`** via `Tracer::flush()` or **`LOOKOUT_TRACING_AUTO_FLUSH=true`**. Ensure the project allows trace ingest in **Lookout → Project settings → Monitoring modes**; otherwise the API returns **403**.

1. **Middleware (order matters):** register **`lookoutTracing.continueTrace`** first, then **`lookoutTracing.performance`**, or set **`LOOKOUT_PERFORMANCE_AUTO_MIDDLEWARE=true`** to append only the performance middleware to `web` and `api` (you still add `continueTrace` yourself if it is not already in those groups).
2. **Sampling:** default **`RateSampler`** at **10%** (`LOOKOUT_PERFORMANCE_SAMPLE_RATE=0.1`). Implement `Lookout\Tracing\Performance\Sampler` and set `performance.sampler.class` for custom logic. Traces continued from an incoming traceparent with **`sampled=0`** never record spans (propagation only). Optional **tail sampling** (`LOOKOUT_PERFORMANCE_TAIL_SAMPLING=true`): keep slow roots (`LOOKOUT_PERFORMANCE_TAIL_SLOW_MS`), errors / 5xx, optional `LOOKOUT_PERFORMANCE_TAIL_RESIDUAL_RATE` for a thin random sample of the rest — same theme as lowering head sample rates in production while still capturing outliers.
3. **Limits:** `performance.trace_limits` — max spans per export, max attributes per span / span event, max span events per span.
4. **Hooks:** `Tracing::configureSpans(fn (Span $span) => …)` and `Tracing::configureSpanEvents(fn (array $event) => …|null)` — return **`null`** from the span-event callback to drop an event.
5. **Collectors** (`performance.collectors.*`): HTTP server transaction, **database** queries (child `db.query` spans), **console** / **queue** root transactions, **log** lines as span events, and **HTTP client** spans when you attach **`GuzzleTraceMiddleware`** (see below).

CLI / queue: enable **`LOOKOUT_PERFORMANCE_FLUSH_CLI_QUEUE=true`** to flush after each command or job, or call **`Tracing::flush()`** yourself.

## Rails

For Ruby on Rails, use the copy-paste module under **`packages/lookout-rails/`** in the Lookout repository (`lib/lookout_framework.rb` + README), or a git subtree mirror if you use `SPLIT_LOOKOUT_RAILS_REPO`: `ActiveSupport::Notifications` for controller and Active Job, optional SQL sampling, and `LookoutFramework.report_exception` from your error pipeline.

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

The Lookout app surfaces **Traces**, **Transactions**, and **trace detail** in the web UI; the SDK sends **errors** (`POST /api/ingest`) and, when enabled, **spans** (`POST /api/ingest/trace`) with consistent **`trace_id`** and compact traceparent propagation.

| Server behavior | SDK support |
|-----------------|-------------|
| **`performance_ingest_enabled`** false | Trace ingest returns **403**. Laravel: enable **`performance.sync_from_api`** (Sanctum token + project id) so **`Tracer::isPerformanceEnabled()`** matches the server on boot; or set **`LOOKOUT_PERFORMANCE_ENABLED=false`**. Auto-flush and queue/cli flush log **`lookout.tracing.trace_forbidden`** when **`performance.log_forbidden_trace_ingest`** is true (default). |
| **`GET /api/v1/projects/{id}`** | **`LookoutManagementApi::fetchProject()`** + sync config (see `lookout-tracing.php` **`performance.sync_from_api`**). |
| **429 / flaky network** | **`trace_ingest.max_attempts`**, **`retry_delay_ms`**, **`retry_statuses`** (env: **`LOOKOUT_TRACE_INGEST_*`**) — **`Tracer::flushWithResult()`** uses **`HttpTransport::postJsonWithResponseRetries()`**. **403** is never retried. |

### Implemented building blocks

- **`Lookout\Tracing\Interop\OpenTelemetryTraceConverter`** — OTLP JSON → Lookout: **`toJobPayloads()`** (one row set per `traceId`), **`toLookoutIngestBody()`** when only one trace is present, **`fromLookoutIngestBody()`** for OTLP export from native bodies. Lookout HTTP: **`POST /api/ingest/trace/otlp`** (same auth/gate as **`/api/ingest/trace`**).
- **`Lookout\Tracing\Http\ContinueTracePsr15Middleware`** — PSR-15 traceparent / **`baggage`** parsing (Slim, Mezzio, etc.).
- **`Lookout\Tracing\Support\DataRedactor::redact()`** — recursive redaction for span **`data`** / context-style arrays.
- **`Lookout\Tracing\Testing\TracerInspection::traceIngestBody()`** — stable access to **`buildTraceIngestBody()`** in tests.

### Still optional / app-specific

- Dedicated **OpenTelemetry PHP SDK** exporter package (protobuf / gRPC) — HTTP JSON ingest is covered by **`/api/ingest/trace/otlp`** and the converter.
- **PSR-15 “performance”** middleware (auto HTTP transactions) — today use manual **`Tracing::startTransaction`** or stay on Laravel.
- **Queue-based async flush** with deduplication across workers.

## Scope

**Tracing** supports **manual** transactions/spans (`Tracing::trace()`, `startTransaction`) and optional **performance mode**: sampled **auto spans** for HTTP (middleware), SQL, Artisan, queue, logs, and outbound Guzzle calls, flushed to Lookout’s trace ingest.

**Framework instrumentation** (above) still records **breadcrumbs** for **error reports**; performance collectors add **span trees** for the distributed trace UI when you flush to **`/api/ingest/trace`**.

**Crons:** Lookout stores check-ins and monitor metadata; it does **not** yet auto-open issues or email you on missed schedules like some hosted cron products—you can build alerting on top (e.g. scheduled jobs reading the API) or extend the app later.
