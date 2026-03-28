<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use GuzzleHttp\Promise\Create;
use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class GuzzleTraceMiddleware
{
    /**
     * Adds traceparent and baggage headers from {@see Tracer::outgoingTraceHeaders()}.
     * When performance monitoring is enabled and a current span exists, records an {@code http.client} child span.
     */
    public static function create(?Tracer $tracer = null): callable
    {
        $tracer ??= Tracer::instance();

        return static function (callable $handler) use ($tracer) {
            return static function (RequestInterface $request, array $options) use ($handler, $tracer) {
                $headers = $tracer->outgoingTraceHeaders();

                $tp = TraceWireHeaders::HTTP_TRACEPARENT;
                $request = $request
                    ->withHeader($tp, $headers[$tp])
                    ->withHeader(TraceWireHeaders::HTTP_BAGGAGE, $headers[TraceWireHeaders::HTTP_BAGGAGE]);

                $parent = $tracer->getCurrentSpan();
                $span = null;
                if ($tracer->isPerformanceEnabled()
                    && $tracer->shouldRecordHttpClientSpans()
                    && $tracer->isSpanRecordingEnabled()
                    && $parent !== null
                    && ! $parent->isFinished()) {
                    $uri = (string) $request->getUri();
                    $desc = $request->getMethod().' '.$uri;
                    if (strlen($desc) > 512) {
                        $desc = substr($desc, 0, 509).'…';
                    }
                    $span = $parent->startChild(SpanOperation::HTTP_CLIENT, $desc);
                    $host = parse_url($uri, PHP_URL_HOST);
                    if (is_string($host) && $host !== '') {
                        $span->setData(['server.address' => $host]);
                    }
                }

                $promise = $handler($request, $options);

                if ($span === null) {
                    return $promise;
                }

                return $promise->then(
                    static function ($response) use ($span) {
                        if (! $span->isFinished()) {
                            $code = is_object($response) && method_exists($response, 'getStatusCode')
                                ? $response->getStatusCode()
                                : null;
                            if ($code !== null) {
                                $span->setData(['http.status_code' => $code]);
                            }
                            if ($code !== null && $code >= 500) {
                                $span->setStatus('internal_error');
                            }
                            $span->finish();
                        }

                        return $response;
                    },
                    static function ($reason) use ($span) {
                        if (! $span->isFinished()) {
                            $span->setStatus('internal_error');
                            if ($reason instanceof Throwable) {
                                $span->setData(['error' => $reason->getMessage()]);
                            }
                            $span->finish();
                        }

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }
}
