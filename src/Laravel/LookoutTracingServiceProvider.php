<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler as FoundationExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Profiling\ProfileClient;
use Lookout\Tracing\Reporting\ErrorReportClient;
use Lookout\Tracing\Support\LookoutManagementApi;
use Lookout\Tracing\Support\MemoryPeakReset;
use Lookout\Tracing\Tracer;

final class LookoutTracingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/lookout-tracing.php', 'lookout-tracing');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/lookout-tracing.php' => config_path('lookout-tracing.php'),
        ], 'lookout-tracing-config');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('lookoutTracing.continueTrace', ContinueTraceMiddleware::class);
        $router->aliasMiddleware('lookoutTracing.performance', PerformanceMiddleware::class);

        $this->configureTracerFromConfig();
        $this->overridePerformanceEnabledFromManagementApi();
        $this->configureCronClientFromConfig();
        $this->configureProfileClientFromConfig();
        $this->configureErrorReportClient();

        $this->registerFrameworkInstrumentation();
        $this->registerExtendedInstrumentation();
        $this->registerOctanePerformanceHooks();
        $this->registerExceptionReporting();

        if (config('lookout-tracing.auto_flush', false)) {
            /** @var Application $app */
            $app = $this->app;
            $app->terminating(static function () {
                TraceIngestFlushReporter::flushWithReporting();
            });
        }

        $this->registerPerformanceMiddlewareGroups($router);
    }

    protected function registerPerformanceMiddlewareGroups(Router $router): void
    {
        $perf = config('lookout-tracing.performance');
        if (! is_array($perf) || empty($perf['enabled']) || empty($perf['middleware_auto_register'])) {
            return;
        }

        foreach (['web', 'api'] as $group) {
            $router->pushMiddlewareToGroup($group, PerformanceMiddleware::class);
        }
    }

    protected function configureTracerFromConfig(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }

        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        $perf = is_array($cfg['performance'] ?? null) ? $cfg['performance'] : [];
        $samplerSpec = is_array($perf['sampler'] ?? null) ? $perf['sampler'] : [];
        $sampler = Tracer::makeSamplerFromSpec($samplerSpec);

        $ti = is_array($cfg['trace_ingest'] ?? null) ? $cfg['trace_ingest'] : [];
        $retryStatuses = $ti['retry_statuses'] ?? [429];
        if (! is_array($retryStatuses)) {
            $retryStatuses = [429];
        }
        $retryStatuses = array_values(array_filter(array_map('intval', $retryStatuses), static fn (int $c): bool => $c > 0));
        if ($retryStatuses === []) {
            $retryStatuses = [429];
        }

        Tracer::instance()->configure([
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'ingest_trace_path' => $cfg['ingest_trace_path'] ?? '/api/ingest/trace',
            'environment' => $cfg['environment'] ?? null,
            'release' => $cfg['release'] ?? null,
            'performance_enabled' => (bool) ($perf['enabled'] ?? false),
            'http_client_spans' => (bool) (($perf['collectors']['http_client'] ?? true)),
            'trace_limits' => is_array($perf['trace_limits'] ?? null) ? $perf['trace_limits'] : null,
            'sampler' => $sampler,
            'trace_ingest_max_attempts' => (int) ($ti['max_attempts'] ?? 1),
            'trace_ingest_retry_delay_ms' => (int) ($ti['retry_delay_ms'] ?? 250),
            'trace_ingest_retry_statuses' => array_values(array_filter(array_map('intval', $retryStatuses))),
        ]);
    }

    protected function overridePerformanceEnabledFromManagementApi(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }
        $perf = is_array($cfg['performance'] ?? null) ? $cfg['performance'] : [];
        $sync = is_array($perf['sync_from_api'] ?? null) ? $perf['sync_from_api'] : [];
        if (empty($sync['enabled'])) {
            return;
        }
        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        $token = isset($sync['bearer_token']) && is_string($sync['bearer_token']) ? trim($sync['bearer_token']) : '';
        $projectId = isset($sync['project_id']) && is_string($sync['project_id']) ? trim($sync['project_id']) : '';
        if ($base === '' || $token === '' || $projectId === '') {
            return;
        }

        $data = LookoutManagementApi::fetchProject($base, $token, $projectId);
        if ($data === null || ! array_key_exists('performance_ingest_enabled', $data)) {
            return;
        }

        Tracer::instance()->configure([
            'performance_enabled' => (bool) $data['performance_ingest_enabled'],
        ]);
    }

    protected function configureCronClientFromConfig(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }

        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        CronClient::configure([
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'cron_ingest_path' => $cfg['cron_ingest_path'] ?? '/api/ingest/cron',
        ]);
    }

    protected function configureProfileClientFromConfig(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }

        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        ProfileClient::configure([
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'profile_ingest_path' => $cfg['profile_ingest_path'] ?? '/api/ingest/profile',
        ]);
    }

    protected function configureErrorReportClient(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }
        ErrorReportClient::instance()->configureFromLookoutConfig($cfg);
    }

    protected function registerFrameworkInstrumentation(): void
    {
        $events = $this->app->make(Dispatcher::class);
        FrameworkInstrumentation::register($events);
        PerformanceInstrumentation::register($events);
    }

    protected function registerOctanePerformanceHooks(): void
    {
        $requestReceived = 'Laravel\\Octane\\Events\\RequestReceived';
        if (! class_exists($requestReceived)) {
            return;
        }
        $events = $this->app->make(Dispatcher::class);
        $events->listen($requestReceived, static function (): void {
            if (! Tracer::instance()->isPerformanceEnabled()) {
                return;
            }
            MemoryPeakReset::beforeUnitOfWork();
        });
    }

    protected function registerExtendedInstrumentation(): void
    {
        $events = $this->app->make(Dispatcher::class);
        ExtendedBreadcrumbInstrumentation::register($events);
        ExtendedBreadcrumbInstrumentation::registerViewComposers($this->app);

        $this->app->booted(function (): void {
            ExtendedBreadcrumbInstrumentation::registerRedisListener();
            PerformanceInstrumentation::registerRedisPerformanceListener();
        });

        $inst = config('lookout-tracing.instrumentation');
        if (is_array($inst) && ! empty($inst['enabled']) && ! empty($inst['dump'])) {
            DumpInstrumentation::register();
        }
    }

    protected function registerExceptionReporting(): void
    {
        $this->app->afterResolving(ExceptionHandlerContract::class, function (ExceptionHandlerContract $handler): void {
            if (! $handler instanceof FoundationExceptionHandler) {
                return;
            }
            $handler->reportable(function (\Throwable $e): void {
                ExceptionReporter::report($e, app());
            });
        });
    }
}
