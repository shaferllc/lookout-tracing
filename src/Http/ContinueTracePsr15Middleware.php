<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use Lookout\Tracing\Laravel\ContinueTraceMiddleware;
use Lookout\Tracing\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware: parse {@code sentry-trace} and {@code baggage} like {@see ContinueTraceMiddleware}.
 *
 * Register early in your stack; place performance / transaction middleware after this if you add one.
 */
final class ContinueTracePsr15Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Tracer::instance()->continueTrace(
            $request->getHeaderLine('sentry-trace') ?: null,
            $request->getHeaderLine('baggage') ?: null,
        );

        return $handler->handle($request);
    }
}
