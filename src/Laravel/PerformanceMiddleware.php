<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Lookout\Tracing\Support\MemoryPeakReset;
use Lookout\Tracing\Tracer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts an {@code http.server} transaction when performance monitoring is enabled and no span is active.
 *
 * Register <strong>after</strong> {@see ContinueTraceMiddleware} so {@code sentry-trace} is parsed first.
 */
final class PerformanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Tracer::instance()->isPerformanceEnabled()) {
            return $next($request);
        }
        $perf = config('lookout-tracing.performance');
        if (! is_array($perf)) {
            return $next($request);
        }
        $collectors = is_array($perf['collectors'] ?? null) ? $perf['collectors'] : [];
        if (empty($collectors['http_server'])) {
            return $next($request);
        }

        $name = self::transactionName($request);
        PerformanceInstrumentation::resetHttpRequestCounters();
        MemoryPeakReset::beforeUnitOfWork();
        Tracer::instance()->startAutoHttpServerTransaction($name);

        return $next($request);
    }

    private static function transactionName(Request $request): string
    {
        $route = $request->route();
        if ($route !== null) {
            $n = $route->getName();
            if (is_string($n) && $n !== '') {
                return $request->getMethod().' '.$n;
            }
            $uri = $route->uri();
            if (is_string($uri) && $uri !== '') {
                return $request->getMethod().' /'.$uri;
            }
        }

        return $request->getMethod().' '.$request->path();
    }
}
