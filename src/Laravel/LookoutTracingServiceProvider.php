<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Profiling\ProfileClient;
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

        $this->configureTracerFromConfig();
        $this->configureCronClientFromConfig();
        $this->configureProfileClientFromConfig();

        if (config('lookout-tracing.auto_flush', false)) {
            /** @var Application $app */
            $app = $this->app;
            $app->terminating(static function () {
                Tracer::instance()->flush();
            });
        }
    }

    protected function configureTracerFromConfig(): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return;
        }

        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        Tracer::instance()->configure([
            'api_key' => $cfg['api_key'] ?? null,
            'base_uri' => $base !== '' ? $base : null,
            'ingest_trace_path' => $cfg['ingest_trace_path'] ?? '/api/ingest/trace',
            'environment' => $cfg['environment'] ?? null,
            'release' => $cfg['release'] ?? null,
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
}
