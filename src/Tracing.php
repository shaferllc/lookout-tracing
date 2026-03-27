<?php

declare(strict_types=1);

namespace Lookout\Tracing;

use Sentry\continueTrace;
use Sentry\trace;

/**
 * Convenience entry points (similar to {@see continueTrace()} / {@see trace()}).
 */
final class Tracing
{
    public static function tracer(): Tracer
    {
        return Tracer::instance();
    }

    public static function continueTrace(?string $sentryTraceHeader, ?string $baggageHeader = null): void
    {
        Tracer::instance()->continueTrace($sentryTraceHeader, $baggageHeader);
    }

    public static function traceparent(): string
    {
        return Tracer::instance()->traceparent();
    }

    public static function baggage(): string
    {
        return Tracer::instance()->baggageHeader();
    }

    /**
     * @return array{sentry-trace: string, baggage: string}
     */
    public static function outgoingHeaders(): array
    {
        return Tracer::instance()->outgoingTraceHeaders();
    }

    public static function startTransaction(string $name, string $op = 'http.server'): Span
    {
        return Tracer::instance()->startTransaction($name, $op);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function trace(callable $callback, string $op, ?string $description = null): mixed
    {
        return Tracer::instance()->trace($callback, $op, $description);
    }

    public static function flush(): bool
    {
        return Tracer::instance()->flush();
    }
}
