<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Tracer;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Decorates a PSR-18 {@see ClientInterface} with traceparent / {@code baggage} headers and optional
 * {@code http.client} spans (same rules as {@see GuzzleTraceMiddleware}).
 *
 * Requires {@code psr/http-client} (interface only) and a concrete client (e.g. Guzzle 7).
 */
final class Psr18TraceClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $inner,
        private ?Tracer $tracer = null,
    ) {
        $this->tracer ??= Tracer::instance();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $tracer = $this->tracer;
        $headers = $tracer->outgoingTraceHeaders();
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $parent = $tracer->getCurrentSpan();
        $span = null;
        if ($tracer->isPerformanceEnabled()
            && $tracer->shouldRecordHttpClientSpans()
            && $tracer->isSpanRecordingEnabled()
            && $parent !== null
            && ! $parent->isFinished()) {
            $uri = (string) $request->getUri();
            $method = $request->getMethod();
            $desc = $method.' '.$uri;
            if (strlen($desc) > 512) {
                $desc = substr($desc, 0, 509).'…';
            }
            $span = $parent->startChild(SpanOperation::HTTP_CLIENT, $desc);
        }

        try {
            $response = $this->inner->sendRequest($request);
        } catch (Throwable $e) {
            if ($span !== null && ! $span->isFinished()) {
                $span->setStatus('internal_error');
                $span->setData(['error' => $e->getMessage()]);
                $span->finish();
            }
            throw $e;
        }

        if ($span !== null && ! $span->isFinished()) {
            $code = $response->getStatusCode();
            $span->setData(['http.status_code' => $code]);
            if ($code >= 500) {
                $span->setStatus('internal_error');
            }
            $span->finish();
        }

        return $response;
    }
}
