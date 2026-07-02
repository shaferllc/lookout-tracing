<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Lookout\Tracing\Profiling\AutoProfiler;
use Lookout\Tracing\Support\IngestSelfMonitoring;
use Lookout\Tracing\Support\MemoryPeakReset;
use Lookout\Tracing\Support\RequestRouteIgnore;
use Lookout\Tracing\Tracer;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Starts an {@code http.server} transaction when performance monitoring is enabled and no span is active.
 *
 * Register <strong>after</strong> {@see ContinueTraceMiddleware} so incoming traceparent is parsed first.
 */
final class PerformanceMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (IngestSelfMonitoring::shouldSkipMonitoring($request)) {
            return $next($request);
        }
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
        if (self::routeIsIgnored($request, $perf['ignore_routes'] ?? [])) {
            return $next($request);
        }

        $name = self::transactionName($request);
        PerformanceInstrumentation::resetHttpRequestCounters();
        MemoryPeakReset::beforeUnitOfWork();
        Tracer::instance()->startAutoHttpServerTransaction($name);
        AutoProfiler::maybeStart();

        return $next($request);
    }

    /**
     * True when performance.ignore_routes (local config merged with the dashboard's
     * ignored-request-routes) matches this request's route name, route URI, or path.
     */
    private static function routeIsIgnored(Request $request, mixed $patterns): bool
    {
        $patterns = RequestRouteIgnore::normalize($patterns);
        if ($patterns === []) {
            return false;
        }

        $route = $request->route();
        $routeName = null;
        $routeUri = null;
        if ($route !== null) {
            $n = $route->getName();
            $routeName = is_string($n) ? $n : null;
            $uri = $route->uri();
            $routeUri = is_string($uri) ? $uri : null;
        }

        return RequestRouteIgnore::matches($patterns, $routeName, $routeUri, $request->path());
    }

    private static function transactionName(Request $request): string
    {
        $route = $request->route();
        if ($route !== null) {
            // Skip the synthetic `generated::<hash>` names route:cache assigns to unnamed
            // routes — the URI is the meaningful identity for those.
            $n = $route->getName();
            if (is_string($n) && $n !== '' && ! str_starts_with($n, 'generated::')) {
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
