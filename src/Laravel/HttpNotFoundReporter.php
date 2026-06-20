<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Lookout\Tracing\Reporting\ErrorReportClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reports HTTP 404 responses to Lookout. Laravel does not invoke {@see ExceptionReporter} for
 * {@see NotFoundHttpException} by default, so we listen for completed requests instead.
 */
final class HttpNotFoundReporter
{
    public static function register(Dispatcher $events): void
    {
        $events->listen(RequestHandled::class, [self::class, 'onRequestHandled']);
    }

    public static function enabled(): bool
    {
        if (! ExceptionReporter::reportingEnabled()) {
            return false;
        }

        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return false;
        }

        return (bool) ($cfg['report_http_404'] ?? true);
    }

    public static function onRequestHandled(RequestHandled $event): void
    {
        if (! self::enabled()) {
            return;
        }

        if ($event->response->getStatusCode() !== 404) {
            return;
        }

        $request = $event->request;
        $app = function_exists('app') ? app() : null;
        if (! $app instanceof Application) {
            $app = null;
        }

        ErrorReportClient::instance()->reportHttpNotFound(
            $request->method(),
            $request->path(),
            $request->fullUrl(),
            NotFoundHttpException::class,
            $app,
            'php',
        );
    }
}
