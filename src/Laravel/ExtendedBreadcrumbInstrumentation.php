<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Lookout\Tracing\BreadcrumbBuffer;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Optional recorders as breadcrumbs: cache, Redis, views, outbound HTTP, response metadata.
 */
final class ExtendedBreadcrumbInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        $cfg = config('lookout-tracing.instrumentation');
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return;
        }

        if (! empty($cfg['cache'])) {
            self::registerCache($events);
        }
        if (! empty($cfg['outbound_http'])) {
            self::registerOutboundHttp($events);
        }
        if (! empty($cfg['response_detail'])) {
            self::registerResponseDetail($events);
        }

        if (! empty($cfg['database_transactions'])) {
            self::registerDatabaseTransactions($events);
        }
    }

    public static function registerViewComposers(Application $app): void
    {
        $cfg = config('lookout-tracing.instrumentation');
        if (! is_array($cfg) || empty($cfg['enabled']) || empty($cfg['views'])) {
            return;
        }
        $app->callAfterResolving('view', function (ViewFactory $factory): void {
            $factory->composer('*', function (View $view): void {
                try {
                    $name = $view->name();
                } catch (Throwable) {
                    $name = 'view';
                }
                if (! is_string($name) || $name === '') {
                    $name = 'view';
                }
                BreadcrumbBuffer::add('view', 'View: '.substr($name, 0, 200), 'debug', ['view' => $name], 'view');
            });
        });
    }

    public static function registerRedisListener(): void
    {
        $cfg = config('lookout-tracing.instrumentation');
        if (! is_array($cfg) || empty($cfg['enabled']) || empty($cfg['redis'])) {
            return;
        }
        if (! class_exists(Redis::class)) {
            return;
        }
        try {
            Redis::listen(function (mixed $command): void {
                $cmd = is_object($command) && isset($command->command) ? (string) $command->command : 'redis';
                $time = is_object($command) && isset($command->time) ? (float) $command->time : null;
                $data = ['command' => substr($cmd, 0, 256)];
                if ($time !== null) {
                    $data['time_ms'] = $time;
                }
                BreadcrumbBuffer::add('redis', 'Redis: '.substr($cmd, 0, 120), 'debug', $data, 'redis');
            });
        } catch (Throwable) {
            // Redis not configured
        }
    }

    private static function registerCache(Dispatcher $events): void
    {
        if (! class_exists(CacheHit::class)) {
            return;
        }
        $events->listen(CacheHit::class, function (CacheHit $e): void {
            $key = is_string($e->key) ? $e->key : '';
            BreadcrumbBuffer::add('cache', 'Cache hit: '.substr($key, 0, 200), 'debug', ['key' => $key], 'cache');
        });
        $events->listen(CacheMissed::class, function (CacheMissed $e): void {
            $key = is_string($e->key) ? $e->key : '';
            BreadcrumbBuffer::add('cache', 'Cache miss: '.substr($key, 0, 200), 'debug', ['key' => $key], 'cache');
        });
    }

    private static function registerOutboundHttp(Dispatcher $events): void
    {
        if (! class_exists(RequestSending::class)) {
            return;
        }
        $events->listen(RequestSending::class, function (RequestSending $e): void {
            $req = $e->request;
            $uri = method_exists($req, 'url') ? (string) $req->url() : '';
            $method = method_exists($req, 'method') ? (string) $req->method() : 'GET';
            BreadcrumbBuffer::add('http.client', $method.' '.substr($uri, 0, 500), 'info', [
                'method' => $method,
                'url' => substr($uri, 0, 2048),
            ], 'http');
        });
        if (class_exists(ResponseReceived::class)) {
            $events->listen(ResponseReceived::class, function (ResponseReceived $e): void {
                $res = $e->response;
                $code = method_exists($res, 'status') ? (int) $res->status() : 0;
                BreadcrumbBuffer::add('http.client', 'Response: '.$code, $code >= 400 ? 'warning' : 'info', [
                    'status' => $code,
                ], 'http');
            });
        }
    }

    private static function registerDatabaseTransactions(Dispatcher $events): void
    {
        if (! class_exists(TransactionBeginning::class)) {
            return;
        }
        $events->listen(TransactionBeginning::class, function (TransactionBeginning $e): void {
            BreadcrumbBuffer::add('db.transaction', 'Transaction beginning', 'info', [
                'connection' => $e->connectionName,
            ], 'database');
        });
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $e): void {
            BreadcrumbBuffer::add('db.transaction', 'Transaction committed', 'info', [
                'connection' => $e->connectionName,
            ], 'database');
        });
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $e): void {
            BreadcrumbBuffer::add('db.transaction', 'Transaction rolled back', 'warning', [
                'connection' => $e->connectionName,
            ], 'database');
        });
    }

    private static function registerResponseDetail(Dispatcher $events): void
    {
        if (! class_exists(ResponsePrepared::class)) {
            return;
        }
        $events->listen(ResponsePrepared::class, function (ResponsePrepared $e): void {
            try {
                $req = $e->request;
                $res = $e->response;
                if (! $req instanceof SymfonyRequest || ! $res instanceof SymfonyResponse) {
                    return;
                }
                $ct = $res->headers->get('Content-Type', '');
                $len = $res->headers->get('Content-Length');
                BreadcrumbBuffer::add('http', 'Response meta: '.$res->getStatusCode(), 'info', array_filter([
                    'content_type' => is_string($ct) ? substr($ct, 0, 128) : null,
                    'content_length' => is_string($len) ? $len : null,
                ]), 'response');
            } catch (Throwable) {
                // ignore
            }
        });
    }
}
