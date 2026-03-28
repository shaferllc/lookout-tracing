<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler as FoundationExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Laravel\Console\InstallLookoutCommand;
use Lookout\Tracing\Logging\LogIngestClient;
use Lookout\Tracing\Metrics\MetricsIngestClient;
use Lookout\Tracing\Profiling\ProfileClient;
use Lookout\Tracing\Reporting\ErrorReportClient;
use Lookout\Tracing\Support\DeploymentDefaults;
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
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallLookoutCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/config/lookout-tracing.php' => config_path('lookout-tracing.php'),
        ], 'lookout-tracing-config');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('lookoutTracing.continueTrace', ContinueTraceMiddleware::class);
        $router->aliasMiddleware('lookoutTracing.performance', PerformanceMiddleware::class);

        $this->configureTracerFromConfig();
        $this->configureLogIngestFromConfig();
        $this->configureMetricsIngestFromConfig();
        $this->overridePerformanceEnabledFromManagementApi();
        $this->configureCronClientFromConfig();
        $this->configureProfileClientFromConfig();
        $this->configureErrorReportClient();

        $this->applyComprehensiveCollectionConfiguration();

        $this->registerFrameworkInstrumentation();
        $this->registerQueueTracePayloadHook();
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

        $logCfg = config('lookout-tracing.logging');
        if (is_array($logCfg) && ! empty($logCfg['enabled']) && ! empty($logCfg['flush_on_terminate'])) {
            /** @var Application $app */
            $app = $this->app;
            $app->terminating(static function () {
                \lookout_logger()->flush();
            });
        }

        $metricCfg = config('lookout-tracing.metrics');
        if (is_array($metricCfg) && ! empty($metricCfg['enabled']) && ! empty($metricCfg['flush_on_terminate'])) {
            /** @var Application $app */
            $app = $this->app;
            $app->terminating(static function () {
                \lookout_metrics()->flush();
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
        $tailSampling = is_array($perf['tail_sampling'] ?? null) ? $perf['tail_sampling'] : [];

        $ti = is_array($cfg['trace_ingest'] ?? null) ? $cfg['trace_ingest'] : [];
        $retryStatuses = $ti['retry_statuses'] ?? [429];
        if (! is_array($retryStatuses)) {
            $retryStatuses = [429];
        }
        $retryStatuses = array_values(array_filter(array_map('intval', $retryStatuses), static fn (int $c): bool => $c > 0));
        if ($retryStatuses === []) {
            $retryStatuses = [429];
        }

        $autoDeploy = DeploymentDefaults::fromEnvironment();
        $releaseCfg = isset($cfg['release']) && is_string($cfg['release']) ? trim($cfg['release']) : '';
        if ($releaseCfg === '') {
            $releaseCfg = is_string($autoDeploy['release'] ?? null) ? trim($autoDeploy['release']) : '';
        }
        $releaseForTracer = $releaseCfg !== '' ? $releaseCfg : null;

        $commitCfg = isset($cfg['commit_sha']) && is_string($cfg['commit_sha']) ? trim($cfg['commit_sha']) : '';
        if ($commitCfg === '') {
            $commitCfg = is_string($autoDeploy['commit_sha'] ?? null) ? trim($autoDeploy['commit_sha']) : '';
        }
        $commitForTracer = $commitCfg !== '' ? strtolower(substr($commitCfg, 0, 64)) : null;

        $deployedUnix = $this->parseDeployedAtUnixFromConfig($cfg['deployed_at'] ?? null);
        if ($deployedUnix === null && isset($autoDeploy['deployed_at_unix'])) {
            $deployedUnix = $autoDeploy['deployed_at_unix'];
        }

        Tracer::instance()->configure([
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'ingest_trace_path' => $cfg['ingest_trace_path'] ?? '/api/ingest/trace',
            'environment' => $cfg['environment'] ?? null,
            'release' => $releaseForTracer,
            'commit_sha' => $commitForTracer,
            'deployed_at' => $deployedUnix,
            'performance_enabled' => (bool) ($perf['enabled'] ?? false),
            'http_client_spans' => (bool) (($perf['collectors']['http_client'] ?? true)),
            'trace_limits' => is_array($perf['trace_limits'] ?? null) ? $perf['trace_limits'] : null,
            'sampler' => $sampler,
            'tail_sampling' => $tailSampling,
            'trace_ingest_max_attempts' => (int) ($ti['max_attempts'] ?? 1),
            'trace_ingest_retry_delay_ms' => (int) ($ti['retry_delay_ms'] ?? 250),
            'trace_ingest_retry_statuses' => array_values(array_filter(array_map('intval', $retryStatuses))),
        ]);
    }

    protected function configureLogIngestFromConfig(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }

        $log = is_array($cfg['logging'] ?? null) ? $cfg['logging'] : [];
        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        $path = isset($cfg['log_ingest_path']) && is_string($cfg['log_ingest_path']) ? $cfg['log_ingest_path'] : '/api/ingest/log';
        $path = '/'.ltrim(trim($path), '/');

        $releaseCfg = isset($cfg['release']) && is_string($cfg['release']) ? trim($cfg['release']) : '';
        $releaseForLogs = $releaseCfg !== '' ? $releaseCfg : null;

        LogIngestClient::configure([
            'enabled' => (bool) ($log['enabled'] ?? false),
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'log_ingest_path' => $path,
            'environment' => $cfg['environment'] ?? null,
            'release' => $releaseForLogs,
            'max_buffer' => (int) ($log['max_buffer'] ?? 50),
        ]);
    }

    protected function configureMetricsIngestFromConfig(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }

        $m = is_array($cfg['metrics'] ?? null) ? $cfg['metrics'] : [];
        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        $path = isset($cfg['metric_ingest_path']) && is_string($cfg['metric_ingest_path']) ? $cfg['metric_ingest_path'] : '/api/ingest/metric';
        $path = '/'.ltrim(trim($path), '/');

        $releaseCfg = isset($cfg['release']) && is_string($cfg['release']) ? trim($cfg['release']) : '';
        $releaseForMetrics = $releaseCfg !== '' ? $releaseCfg : null;

        MetricsIngestClient::configure([
            'enabled' => (bool) ($m['enabled'] ?? false),
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'metric_ingest_path' => $path,
            'environment' => $cfg['environment'] ?? null,
            'release' => $releaseForMetrics,
            'max_buffer' => (int) ($m['max_buffer'] ?? 500),
        ]);
    }

    private function parseDeployedAtUnixFromConfig(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $u = (float) $value;
            if ($u > 9999999999) {
                $u /= 1000.0;
            }

            return $u > 0 ? $u : null;
        }
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime(trim($value));

            return $ts !== false ? (float) $ts : null;
        }

        return null;
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

    /**
     * When {@code instrumentation.comprehensive_collection} is true, turn on optional breadcrumb
     * recorders and performance collectors for broad request context (cache, Redis, views, HTTP, DB, …).
     */
    protected function applyComprehensiveCollectionConfiguration(): void
    {
        $inst = config('lookout-tracing.instrumentation');
        if (! is_array($inst) || empty($inst['comprehensive_collection'])) {
            return;
        }

        config([
            'lookout-tracing.instrumentation' => array_merge($inst, [
                'cache' => true,
                'redis' => true,
                'views' => true,
                'outbound_http' => true,
                'response_detail' => true,
                'database' => true,
                'database_transactions' => true,
            ]),
        ]);

        $perf = config('lookout-tracing.performance');
        if (! is_array($perf)) {
            return;
        }
        $collectors = is_array($perf['collectors'] ?? null) ? $perf['collectors'] : [];
        $collectors['cache'] = true;
        $collectors['redis'] = true;
        $collectors['view'] = true;
        $collectors['log'] = true;
        config([
            'lookout-tracing.performance' => array_merge($perf, [
                'collectors' => $collectors,
            ]),
        ]);
    }

    protected function registerFrameworkInstrumentation(): void
    {
        $events = $this->app->make(Dispatcher::class);
        FrameworkInstrumentation::register($events);
        PerformanceInstrumentation::register($events);
    }

    protected function registerQueueTracePayloadHook(): void
    {
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            return PerformanceInstrumentation::mergeQueuePayloadTraceContext($connection, $queue, $payload);
        });
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
