<?php

declare(strict_types=1);

namespace Lookout\Tracing;

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

    /**
     * @return array{
     *     ok: bool,
     *     skipped: bool,
     *     status: int|null,
     *     response: array<string, mixed>|null,
     * }
     */
    public static function flushWithResult(): array
    {
        return Tracer::instance()->flushWithResult();
    }

    /**
     * @param  callable(Span): void  $callback
     */
    public static function configureSpans(callable $callback): void
    {
        Tracer::instance()->configureSpans($callback);
    }

    /**
     * @param  callable(array<string, mixed>): (?array<string, mixed>)  $callback  Return null to drop a span event
     */
    public static function configureSpanEvents(callable $callback): void
    {
        Tracer::instance()->configureSpanEvents($callback);
    }
}
