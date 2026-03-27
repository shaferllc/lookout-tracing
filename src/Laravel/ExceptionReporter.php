<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Lookout\Tracing\BreadcrumbBuffer;
use Lookout\Tracing\ErrorIngestClient;
use Lookout\Tracing\Tracer;
use Throwable;

/**
 * Sends uncaught exceptions to {@code POST /api/ingest} with breadcrumbs and trace correlation.
 */
final class ExceptionReporter
{
    /**
     * Report a throwable to Lookout when {@see config('lookout-tracing.report_exceptions')} is true and {@see api_key} is set.
     */
    public static function report(Throwable $e, ?Application $app = null): void
    {
        try {
            self::doReport($e, $app);
        } catch (Throwable) {
            // Never break the host app's exception pipeline
        }
    }

    private static function doReport(Throwable $e, ?Application $app = null): void
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || empty($cfg['report_exceptions'])) {
            return;
        }
        $apiKey = $cfg['api_key'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            return;
        }

        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim($cfg['base_uri'], '/') : '';
        if ($base === '') {
            return;
        }

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

        $frames = self::stackFramesFromThrowable($e);
        if ($frames !== []) {
            $payload['stack_frames'] = $frames;
        }

        $file = $e->getFile();
        $line = $e->getLine();
        if ($file !== '') {
            $payload['file'] = $file;
        }
        if ($line > 0) {
            $payload['line'] = $line;
        }

        $env = $cfg['environment'] ?? null;
        if (is_string($env) && $env !== '') {
            $payload['environment'] = $env;
        }
        $release = $cfg['release'] ?? null;
        if (is_string($release) && $release !== '') {
            $payload['release'] = $release;
        }

        try {
            $payload = array_merge($payload, Tracer::instance()->errorIngestTraceFields());
        } catch (Throwable) {
            // Tracer not initialized; omit trace fields
        }

        $crumbs = BreadcrumbBuffer::all();
        if ($crumbs !== []) {
            $payload['breadcrumbs'] = $crumbs;
        }

        $laravelCtx = self::buildLaravelContext($app);
        if ($laravelCtx !== []) {
            $existing = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
            $payload['context'] = array_merge($existing, ['laravel' => $laravelCtx]);
        }

        ErrorIngestClient::send($payload, [
            'api_key' => $apiKey,
            'base_uri' => $base,
            'error_ingest_path' => $cfg['error_ingest_path'] ?? '/api/ingest',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildLaravelContext(?Application $app): array
    {
        if ($app === null) {
            if (! function_exists('app')) {
                return [];
            }
            try {
                $app = app();
            } catch (Throwable) {
                return [];
            }
        }

        $out = [
            'framework_version' => $app->version(),
            'php_version' => PHP_VERSION,
        ];

        $job = ActiveContext::queueJob();
        if ($job !== null && $job !== '') {
            $out['queue_job'] = $job;
        }
        $cmd = ActiveContext::consoleCommand();
        if ($cmd !== null && $cmd !== '') {
            $out['artisan_command'] = $cmd;
        }
        $route = ActiveContext::httpRoute();
        if ($route !== null && $route !== '') {
            $out['route'] = $route;
        }

        if ($app->runningInConsole()) {
            $out['entry'] = 'console';
        } else {
            $out['entry'] = 'http';
        }

        try {
            if ($app->bound('request') && $app->make('request') instanceof Request) {
                /** @var Request $req */
                $req = $app->make('request');
                if ($req->getMethod() !== '') {
                    $out['http_method'] = $req->getMethod();
                }
                $path = $req->path();
                if ($path !== '') {
                    $out['path'] = '/'.$path;
                }
            }
        } catch (Throwable) {
            // Request not available
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function stackFramesFromThrowable(Throwable $e): array
    {
        $out = [];
        $i = 0;
        foreach ($e->getTrace() as $t) {
            if (! is_array($t)) {
                continue;
            }
            $row = ['index' => $i];
            if (isset($t['file']) && is_string($t['file']) && $t['file'] !== '') {
                $row['file'] = substr($t['file'], 0, 4096);
            }
            if (isset($t['line'])) {
                $row['line'] = (int) $t['line'];
            }
            if (isset($t['class']) && is_string($t['class']) && $t['class'] !== '') {
                $row['class'] = substr($t['class'], 0, 512);
            }
            if (isset($t['function']) && is_string($t['function']) && $t['function'] !== '') {
                $row['function'] = substr($t['function'], 0, 256);
            }
            if (isset($t['type']) && is_string($t['type']) && $t['type'] !== '') {
                $row['type'] = substr($t['type'], 0, 8);
            }
            if (isset($row['file']) || isset($row['function'])) {
                $out[] = $row;
            }
            $i++;
            if ($i >= 200) {
                break;
            }
        }

        return $out;
    }
}
