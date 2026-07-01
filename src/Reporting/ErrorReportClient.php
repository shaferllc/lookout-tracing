<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

use Illuminate\Contracts\Foundation\Application;
use Lookout\Tracing\BreadcrumbBuffer;
use Lookout\Tracing\ErrorIngestClient;
use Lookout\Tracing\Id;
use Lookout\Tracing\Support\DataRedactor;
use Lookout\Tracing\Support\ErrorSuppressionKey;
use Lookout\Tracing\ThrowableSupport;
use Lookout\Tracing\Tracer;
use Throwable;

/**
 * Error reporting client: middleware pipeline, truncation, sampling, optional queued send + shutdown flush.
 */
final class ErrorReportClient
{
    private static ?self $instance = null;

    private static ?string $lastOccurrenceUuid = null;

    private bool $disabled = false;

    private bool $queueEnabled = true;

    private bool $sendImmediately = false;

    private ReportSampler $sampler;

    private ReportTruncator $truncator;

    private ReportQueue $queue;

    /** @var list<ReportMiddlewareInterface> */
    private array $middleware = [];

    /**
     * Suppression keys (from the dashboard's ignored error groups) as a lookup set: key => true.
     *
     * @var array<string, true>
     */
    private array $suppressedKeys = [];

    /** @var array{api_key: string, base_uri: string, error_ingest_path?: string}|null */
    private ?array $transport = null;

    public function __construct()
    {
        $this->sampler = new ReportSampler(1.0);
        $this->truncator = new ReportTruncator([]);
        $this->queue = new ReportQueue;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function resetInstanceForTesting(): void
    {
        self::$instance = null;
        self::$lastOccurrenceUuid = null;
    }

    /**
     * Occurrence UUID from the last {@see reportThrowable} base payload (for user-feedback forms on error pages).
     * Populated when the SDK builds a report; use with `POST /api/ingest/feedback` and `occurrence_uuid`.
     */
    public static function lastOccurrenceUuid(): ?string
    {
        return self::$lastOccurrenceUuid;
    }

    /**
     * @param  array<string, mixed>  $config  Merged {@code lookout-tracing} + {@code reporting} keys
     */
    public function configureFromLookoutConfig(array $config): void
    {
        $rep = is_array($config['reporting'] ?? null) ? $config['reporting'] : [];

        $this->disabled = (bool) ($rep['disabled'] ?? false)
            || (bool) ($config['disabled'] ?? false);

        $this->queueEnabled = (bool) ($rep['queue'] ?? true);
        $this->sendImmediately = (bool) ($rep['send_immediately'] ?? false);

        $rate = $rep['sample_rate'] ?? 1.0;
        $this->sampler = new ReportSampler(is_numeric($rate) ? (float) $rate : 1.0);

        $limits = is_array($rep['truncation'] ?? null) ? $rep['truncation'] : [];
        $this->truncator = new ReportTruncator($limits);

        $this->middleware = $this->resolveMiddleware($rep);
        $this->suppressedKeys = self::indexSuppressedKeys($rep['suppressed_keys'] ?? []);

        $apiKey = isset($config['api_key']) && is_string($config['api_key']) ? $config['api_key'] : '';
        $base = isset($config['base_uri']) && is_string($config['base_uri']) ? rtrim($config['base_uri'], '/') : '';
        if ($apiKey !== '' && $base !== '') {
            $this->transport = [
                'api_key' => $apiKey,
                'base_uri' => $base,
                'error_ingest_path' => $config['error_ingest_path'] ?? '/api/ingest',
            ];
        } else {
            $this->transport = null;
        }

        if ($this->queueEnabled && ! $this->sendImmediately && ! $this->disabled) {
            $this->queue->registerShutdownFlush(fn () => $this->flush());
        }
    }

    /**
     * @param  array<string, mixed>  $rep
     * @return list<ReportMiddlewareInterface>
     */
    private function resolveMiddleware(array $rep): array
    {
        $classes = $rep['middleware'] ?? null;
        if (! is_array($classes) || $classes === []) {
            return $this->defaultMiddleware($rep);
        }

        $out = [];
        foreach ($classes as $class) {
            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                continue;
            }
            $m = null;
            try {
                if (function_exists('app')) {
                    $m = app()->make($class);
                }
            } catch (Throwable) {
                $m = null;
            }
            if (! $m instanceof ReportMiddlewareInterface) {
                $m = new $class;
            }
            if ($m instanceof ReportMiddlewareInterface) {
                $out[] = $m;
            }
        }

        return $out !== [] ? $out : $this->defaultMiddleware($rep);
    }

    /**
     * @param  array<string, mixed>  $rep
     * @return list<ReportMiddlewareInterface>
     */
    private function defaultMiddleware(array $rep): array
    {
        $app = null;
        if (function_exists('app')) {
            try {
                $app = app();
            } catch (Throwable) {
                $app = null;
            }
        }

        $providers = [];
        $names = $rep['attribute_providers'] ?? [];
        if (is_array($names) && $app !== null) {
            foreach ($names as $class) {
                if (! is_string($class) || ! class_exists($class)) {
                    continue;
                }
                try {
                    $p = $app->make($class);
                } catch (Throwable) {
                    $p = new $class;
                }
                if ($p instanceof AttributeProviderInterface) {
                    $providers[] = $p;
                }
            }
        }

        $hints = $rep['client_solutions'] ?? [];
        if (! is_array($hints)) {
            $hints = [];
        }

        return [
            new Middleware\ApplicationContextMiddleware($app),
            new Middleware\RequestContextMiddleware($app),
            new Middleware\GitInformationMiddleware,
            new Middleware\AttributesMiddleware($providers),
            new Middleware\SolutionsMiddleware($hints),
        ];
    }

    public function reportThrowable(Throwable $e, ?Application $app = null): void
    {
        if ($this->disabled || $this->transport === null) {
            return;
        }
        if (! $this->sampler->shouldKeep()) {
            return;
        }

        Tracer::instance()->markTraceMustExport('error_report');

        $payload = $this->buildBasePayload($e, $app);
        foreach ($this->middleware as $mw) {
            $payload = $mw->handle($payload);
        }
        $payload = $this->truncator->trim($payload);

        // Drop errors the dashboard has ignored so they stop re-ingesting (matched on the final
        // payload's class + message, which is exactly what the server stored and keyed off).
        if ($this->isSuppressed($payload)) {
            return;
        }

        if ($this->sendImmediately || ! $this->queueEnabled) {
            ErrorIngestClient::send($payload, $this->transport);

            return;
        }

        $this->queue->push($payload);
    }

    /**
     * Build the fully-enriched ingest payload for a throwable WITHOUT sending,
     * sampling, or suppression checks. This is the render source for the local
     * debug page: it runs the exact same base builder + middleware pipeline +
     * truncation the sent payload gets, so what you see is what would be
     * reported. Works even when reporting is disabled / no DSN is configured
     * (local dev renders the page with no network dependency).
     *
     * @return array<string, mixed>
     */
    public function buildPayload(Throwable $e, ?Application $app = null): array
    {
        $payload = $this->buildBasePayload($e, $app);
        foreach ($this->middleware as $mw) {
            $payload = $mw->handle($payload);
        }

        return $this->truncator->trim($payload);
    }

    /**
     * Report a completed HTTP 404 response (handled warning, not an uncaught exception).
     */
    public function reportHttpNotFound(
        string $method,
        string $path,
        string $fullUrl,
        ?string $exceptionClass = null,
        ?Application $app = null,
        string $language = 'php',
    ): void {
        if ($this->disabled || $this->transport === null) {
            return;
        }
        if (! $this->shouldReportHttp404()) {
            return;
        }
        if (! $this->sampler->shouldKeep()) {
            return;
        }

        Tracer::instance()->markTraceMustExport('error_report');

        $normalizedPath = self::normalizeHttpPath($path);
        $payload = [
            'message' => self::formatHttpNotFoundMessage($method, $path),
            'exception_class' => $exceptionClass ?? 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
            'level' => 'warning',
            'handled' => true,
            'language' => $language,
            'route' => $normalizedPath,
            'context' => [
                'http' => [
                    'method' => strtoupper($method),
                    'status_code' => 404,
                    'url' => $fullUrl,
                    'path' => $normalizedPath,
                ],
            ],
        ];

        foreach ($this->middleware as $mw) {
            $payload = $mw->handle($payload);
        }
        $payload = $this->truncator->trim($payload);

        $existingOu = $payload['occurrence_uuid'] ?? null;
        if (! is_string($existingOu) || trim($existingOu) === '') {
            $ou = Id::occurrenceUuid();
            $payload['occurrence_uuid'] = $ou;
            self::$lastOccurrenceUuid = $ou;
        } else {
            self::$lastOccurrenceUuid = trim($existingOu);
        }

        if ($this->isSuppressed($payload)) {
            return;
        }

        if ($this->sendImmediately || ! $this->queueEnabled) {
            ErrorIngestClient::send($payload, $this->transport);

            return;
        }

        $this->queue->push($payload);
    }

    /**
     * Whether this payload's error has been ignored on the dashboard (its client suppression key is
     * in the remote-config suppression set). No keys configured → never suppresses.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isSuppressed(array $payload): bool
    {
        if ($this->suppressedKeys === []) {
            return false;
        }

        $class = isset($payload['exception_class']) && is_string($payload['exception_class']) ? $payload['exception_class'] : null;
        $message = isset($payload['message']) && is_string($payload['message']) ? $payload['message'] : null;

        return isset($this->suppressedKeys[ErrorSuppressionKey::compute($class, $message)]);
    }

    /**
     * @param  mixed  $keys
     * @return array<string, true>
     */
    private static function indexSuppressedKeys($keys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        $index = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $index[$key] = true;
            }
        }

        return $index;
    }

    public function flush(): void
    {
        if ($this->disabled || $this->transport === null) {
            return;
        }
        foreach ($this->queue->drain() as $payload) {
            try {
                ErrorIngestClient::send($payload, $this->transport);
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBasePayload(Throwable $e, ?Application $app): array
    {
        $message = $e->getMessage();
        if ($message === '') {
            $message = $e::class;
        }

        $payload = [
            'message' => $message,
            'exception_class' => $e::class,
            'stack_trace' => $e->getTraceAsString(),
            'level' => 'error',
            'language' => 'php',
            'handled' => false,
        ];

        $repCfg = [];
        if (function_exists('config')) {
            try {
                $lc = config('lookout-tracing');
                if (is_array($lc) && is_array($lc['reporting'] ?? null)) {
                    $repCfg = $lc['reporting'];
                }
            } catch (Throwable) {
                $repCfg = [];
            }
        }
        $includeStackArgs = ! empty($repCfg['include_stack_arguments']);

        $frames = ThrowableSupport::stackFramesFromThrowable($e, 200, $includeStackArgs);
        if ($frames !== []) {
            $payload['stack_frames'] = $frames;
        }

        try {
            if (method_exists($e, 'context')) {
                /** @var callable(): array<string, mixed> $ctxFn */
                $ctxFn = [$e, 'context'];
                $exCtx = $ctxFn();
                if (is_array($exCtx) && $exCtx !== []) {
                    $payload['context'] = array_merge(
                        isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [],
                        ['exception_context' => DataRedactor::redact($exCtx)]
                    );
                }
            }
        } catch (Throwable) {
            // ignore invalid exception context
        }

        $file = $e->getFile();
        $line = $e->getLine();
        if ($file !== '') {
            $payload['file'] = $file;
        }
        if ($line > 0) {
            $payload['line'] = $line;
        }

        if (function_exists('config')) {
            try {
                $cfg = config('lookout-tracing');
                if (is_array($cfg)) {
                    $env = $cfg['environment'] ?? null;
                    if (is_string($env) && $env !== '') {
                        $payload['environment'] = $env;
                    }
                    $release = $cfg['release'] ?? null;
                    if (is_string($release) && $release !== '') {
                        $payload['release'] = $release;
                    }
                    $sha = $cfg['commit_sha'] ?? null;
                    if (is_string($sha)) {
                        $sha = strtolower(trim($sha));
                        if ($sha !== '') {
                            $payload['commit_sha'] = substr($sha, 0, 64);
                        }
                    }
                    $dep = $cfg['deployed_at'] ?? null;
                    if (is_numeric($dep)) {
                        $u = (float) $dep;
                        if ($u > 9999999999) {
                            $u /= 1000.0;
                        }
                        if ($u > 0) {
                            $payload['deployed_at'] = $u;
                        }
                    } elseif (is_string($dep) && trim($dep) !== '') {
                        $ts = strtotime(trim($dep));
                        if ($ts !== false) {
                            $payload['deployed_at'] = (float) $ts;
                        }
                    }
                }
            } catch (Throwable) {
                // ignore
            }
        }

        try {
            $payload = array_merge($payload, Tracer::instance()->errorIngestTraceFields());
        } catch (Throwable) {
            // Tracer not initialized
        }

        try {
            foreach (Tracer::instance()->errorIngestPerformanceGroupingHints() as $k => $v) {
                if (! array_key_exists($k, $payload)) {
                    $payload[$k] = $v;
                }
            }
        } catch (Throwable) {
            // Tracer not initialized
        }

        $crumbs = BreadcrumbBuffer::all();
        if ($crumbs !== []) {
            $payload['breadcrumbs'] = $crumbs;
        }

        $existingOu = $payload['occurrence_uuid'] ?? null;
        if (! is_string($existingOu) || trim($existingOu) === '') {
            $ou = Id::occurrenceUuid();
            $payload['occurrence_uuid'] = $ou;
            self::$lastOccurrenceUuid = $ou;
        } else {
            self::$lastOccurrenceUuid = trim($existingOu);
        }

        return $payload;
    }

    private function shouldReportHttp404(): bool
    {
        if (! function_exists('config')) {
            return true;
        }

        try {
            $cfg = config('lookout-tracing');
            if (! is_array($cfg) || empty($cfg['report_exceptions'])) {
                return false;
            }

            return (bool) ($cfg['report_http_404'] ?? true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function formatHttpNotFoundMessage(string $method, string $path): string
    {
        return sprintf('HTTP 404 Not Found: %s %s', strtoupper($method), self::normalizeHttpPath($path));
    }

    public static function normalizeHttpPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}
