<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Lookout\Tracing\BreadcrumbBuffer;
use Throwable;

/**
 * Registers Laravel event subscribers that record breadcrumbs for the next error report.
 */
final class FrameworkInstrumentation
{
    private static int $querySeq = 0;

    public static function register(Dispatcher $events): void
    {
        $cfg = config('lookout-tracing.instrumentation');
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return;
        }

        $max = config('lookout-tracing.breadcrumbs_max');
        if (is_int($max)) {
            BreadcrumbBuffer::configureMaxItems($max);
        }

        if (! empty($cfg['http'])) {
            $events->listen(RouteMatched::class, [self::class, 'onRouteMatched']);
            $events->listen(RequestHandled::class, [self::class, 'onRequestHandled']);
        }

        if (! empty($cfg['console'])) {
            $events->listen(CommandStarting::class, [self::class, 'onCommandStarting']);
            $events->listen(CommandFinished::class, [self::class, 'onCommandFinished']);
        }

        if (! empty($cfg['queue'])) {
            $events->listen(JobProcessing::class, [self::class, 'onJobProcessing']);
            $events->listen(JobProcessed::class, [self::class, 'onJobProcessed']);
            $events->listen(JobFailed::class, [self::class, 'onJobFailed']);
            $events->listen(JobExceptionOccurred::class, [self::class, 'onJobExceptionOccurred']);
        }

        if (! empty($cfg['database'])) {
            $events->listen(QueryExecuted::class, [self::class, 'onQueryExecuted']);
        }

        if (! empty($cfg['log'])) {
            $events->listen(MessageLogged::class, [self::class, 'onMessageLogged']);
        }

        self::registerApplicationEventListeners($events, $cfg);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private static function registerApplicationEventListeners(Dispatcher $events, array $cfg): void
    {
        $allowlist = $cfg['application_event_allowlist'] ?? [];
        if (! is_array($allowlist) || $allowlist === []) {
            return;
        }

        foreach ($allowlist as $class) {
            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                continue;
            }
            $events->listen($class, function (object $event) use ($class): void {
                BreadcrumbBuffer::add(
                    'laravel.event',
                    class_basename($class),
                    'info',
                    ['event' => $class],
                    'event'
                );
            });
        }

        if (empty($cfg['application_events_wildcard'])) {
            return;
        }

        $ignore = $cfg['application_event_ignore_prefixes'] ?? ['Illuminate\\', 'Laravel\\', 'Livewire\\'];
        $ignore = is_array($ignore) ? $ignore : [];

        $events->listen('*', function (mixed $eventName, array $payload) use ($ignore): void {
            if (! is_string($eventName) || $eventName === '' || $eventName === '*') {
                return;
            }
            foreach ($ignore as $prefix) {
                if (! is_string($prefix) || $prefix === '') {
                    continue;
                }
                if (str_starts_with($eventName, $prefix)) {
                    return;
                }
            }
            BreadcrumbBuffer::add('laravel.event', $eventName, 'info', [], 'event');
        });
    }

    public static function onRouteMatched(RouteMatched $event): void
    {
        self::$querySeq = 0;
        BreadcrumbBuffer::clear();
        ActiveContext::reset();
        $route = $event->route;
        $label = $route->getName();
        if (! is_string($label) || $label === '') {
            $label = $route->uri();
        }
        ActiveContext::setHttpRoute(is_string($label) ? $label : '');
        BreadcrumbBuffer::add('http', 'Route matched: '.$label, 'info', [
            'methods' => $route->methods(),
        ], 'routing');
    }

    public static function onRequestHandled(RequestHandled $event): void
    {
        $req = $event->request;
        $res = $event->response;
        $line = $req->getMethod().' '.$req->path().' → '.$res->getStatusCode();
        BreadcrumbBuffer::add('http', $line, 'info', [], 'request');
    }

    public static function onCommandStarting(CommandStarting $event): void
    {
        self::$querySeq = 0;
        BreadcrumbBuffer::clear();
        ActiveContext::reset();
        $name = $event->command ?? 'artisan';
        ActiveContext::setConsoleCommand($name);
        BreadcrumbBuffer::add('console', 'Command: '.$name, 'info', [], 'artisan');
    }

    public static function onCommandFinished(CommandFinished $event): void
    {
        $code = $event->exitCode;
        BreadcrumbBuffer::add('console', 'Finished: '.$event->command.' (exit '.$code.')', $code === 0 ? 'info' : 'warning', [], 'artisan');
        ActiveContext::setConsoleCommand(null);
    }

    public static function onJobProcessing(JobProcessing $event): void
    {
        self::$querySeq = 0;
        BreadcrumbBuffer::clear();
        ActiveContext::reset();
        $name = self::resolveJobName($event->job);
        ActiveContext::setQueueJob($name);
        BreadcrumbBuffer::add('queue', 'Job started: '.$name, 'info', [
            'connection' => $event->connectionName,
        ], 'job');
    }

    public static function onJobProcessed(JobProcessed $event): void
    {
        $name = self::resolveJobName($event->job);
        BreadcrumbBuffer::add('queue', 'Job processed: '.$name, 'info', ['connection' => $event->connectionName], 'job');
        ActiveContext::setQueueJob(null);
    }

    public static function onJobFailed(JobFailed $event): void
    {
        $name = self::resolveJobName($event->job);
        $ex = $event->exception;
        $msg = $ex instanceof Throwable ? $ex->getMessage() : 'unknown';
        BreadcrumbBuffer::add('queue', 'Job failed: '.$name.' — '.$msg, 'error', ['connection' => $event->connectionName], 'job');
        ActiveContext::setQueueJob(null);
    }

    public static function onJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $name = self::resolveJobName($event->job);
        $ex = $event->exception;
        $msg = $ex instanceof Throwable ? $ex->getMessage() : 'unknown';
        BreadcrumbBuffer::add('queue', 'Job exception: '.$name.' — '.$msg, 'error', ['connection' => $event->connectionName], 'job');
    }

    public static function onQueryExecuted(QueryExecuted $event): void
    {
        self::$querySeq++;
        $sample = (int) (config('lookout-tracing.instrumentation.database_sample_every') ?? 1);
        $sample = max(1, $sample);
        if ((self::$querySeq % $sample) !== 0) {
            return;
        }
        $sql = strlen($event->sql) > 500 ? substr($event->sql, 0, 500).'…' : $event->sql;
        BreadcrumbBuffer::add('query', $sql, 'debug', [
            'connection' => $event->connectionName,
            'time_ms' => $event->time,
        ], 'db');
    }

    public static function onMessageLogged(MessageLogged $event): void
    {
        $msg = strlen($event->message) > 2000 ? substr($event->message, 0, 2000).'…' : $event->message;
        BreadcrumbBuffer::add('log', $msg, $event->level, [], 'log');
    }

    private static function resolveJobName(mixed $job): string
    {
        if ($job instanceof QueueJobContract && method_exists($job, 'resolveName')) {
            return (string) $job->resolveName();
        }

        return is_object($job) ? $job::class : 'unknown';
    }
}
