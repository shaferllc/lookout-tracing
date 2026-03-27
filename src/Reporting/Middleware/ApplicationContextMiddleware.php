<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting\Middleware;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Lookout\Tracing\Laravel\ActiveContext;
use Lookout\Tracing\Reporting\ReportMiddlewareInterface;
use Lookout\Tracing\Support\ServerRuntimeSnapshot;
use Throwable;

/**
 * Framework version, entry type, route/job/command hints (shared with legacy ExceptionReporter context).
 */
final class ApplicationContextMiddleware implements ReportMiddlewareInterface
{
    public function __construct(
        private ?Application $app = null,
    ) {}

    public function handle(array $payload): array
    {
        $app = $this->app;
        if ($app === null) {
            if (! function_exists('app')) {
                return $payload;
            }
            try {
                $app = app();
            } catch (Throwable) {
                return $payload;
            }
        }

        $laravel = [
            'framework_version' => $app->version(),
            'php_version' => PHP_VERSION,
        ];

        $job = ActiveContext::queueJob();
        if ($job !== null && $job !== '') {
            $laravel['queue_job'] = $job;
        }
        $cmd = ActiveContext::consoleCommand();
        if ($cmd !== null && $cmd !== '') {
            $laravel['artisan_command'] = $cmd;
        }
        $route = ActiveContext::httpRoute();
        if ($route !== null && $route !== '') {
            $laravel['route'] = $route;
        }

        $laravel['entry'] = $app->runningInConsole() ? 'console' : 'http';

        try {
            if ($app->bound('request') && $app->make('request') instanceof Request) {
                /** @var Request $req */
                $req = $app->make('request');
                if ($req->getMethod() !== '') {
                    $laravel['http_method'] = $req->getMethod();
                }
                $path = $req->path();
                if ($path !== '') {
                    $laravel['path'] = '/'.$path;
                }
            }
        } catch (Throwable) {
            // Request not available
        }

        $existing = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $clientServer = isset($existing['server']) && is_array($existing['server']) ? $existing['server'] : [];
        $server = array_merge($clientServer, ServerRuntimeSnapshot::collect());

        $payload['context'] = array_merge($existing, [
            'laravel' => $laravel,
            'server' => $server,
        ]);

        if (empty($payload['server_name']) && isset($server['hostname']) && is_string($server['hostname']) && $server['hostname'] !== '') {
            $payload['server_name'] = substr($server['hostname'], 0, 128);
        }

        return $payload;
    }
}
