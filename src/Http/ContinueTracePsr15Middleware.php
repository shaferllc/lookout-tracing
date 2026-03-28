<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use Lookout\Tracing\Laravel\ContinueTraceMiddleware;
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware: parse traceparent and {@code baggage} like {@see ContinueTraceMiddleware}.
 *
 * Register early in your stack; place performance / transaction middleware after this if you add one.
 */
final class ContinueTracePsr15Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Tracer::instance()->continueTrace(
            $request->getHeaderLine(TraceWireHeaders::HTTP_TRACEPARENT) ?: null,
            $request->getHeaderLine(TraceWireHeaders::HTTP_BAGGAGE) ?: null,
        );

        return $handler->handle($request);
    }
}
