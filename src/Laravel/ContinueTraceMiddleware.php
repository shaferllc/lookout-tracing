<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extract traceparent and {@code baggage} from the incoming request (web or API).
 */
final class ContinueTraceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        Tracer::instance()->continueTrace(
            $request->headers->get(TraceWireHeaders::HTTP_TRACEPARENT),
            $request->headers->get(TraceWireHeaders::HTTP_BAGGAGE),
        );

        return $next($request);
    }
}
