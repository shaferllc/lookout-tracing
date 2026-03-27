<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Lookout\Tracing\Tracer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extract {@code sentry-trace} and {@code baggage} from the incoming request (web or API).
 */
final class ContinueTraceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        Tracer::instance()->continueTrace(
            $request->headers->get('sentry-trace'),
            $request->headers->get('baggage'),
        );

        return $next($request);
    }
}
