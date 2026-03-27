<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use Lookout\Tracing\Tracer;
use Psr\Http\Message\RequestInterface;

final class GuzzleTraceMiddleware
{
    /**
     * Adds {@code sentry-trace} and {@code baggage} from {@see Tracer::outgoingTraceHeaders()}.
     */
    public static function create(?Tracer $tracer = null): callable
    {
        $tracer ??= Tracer::instance();

        return static function (callable $handler) use ($tracer) {
            return static function (RequestInterface $request, array $options) use ($handler, $tracer) {
                $headers = $tracer->outgoingTraceHeaders();

                $request = $request
                    ->withHeader('sentry-trace', $headers['sentry-trace'])
                    ->withHeader('baggage', $headers['baggage']);

                return $handler($request, $options);
            };
        };
    }
}
