<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use Lookout\Tracing\Span;
use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Throwable;

/**
 * Wraps a {@see HttpClientInterface} to add traceparent / {@code baggage} headers and, when performance
 * monitoring is on, an {@code http.client} child span finished when the lazy response status is resolved.
 *
 * Requires {@code symfony/http-client} (or another package providing {@see HttpClientInterface}).
 */
final class SymfonyHttpClientTraceDecorator implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private ?Tracer $tracer = null,
    ) {
        $this->tracer ??= Tracer::instance();
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $tracer = $this->tracer;
        $traceHeaders = $tracer->outgoingTraceHeaders();
        $options = self::mergeTraceHeaders($options, $traceHeaders);

        $parent = $tracer->getCurrentSpan();
        $span = null;
        if ($tracer->isPerformanceEnabled()
            && $tracer->shouldRecordHttpClientSpans()
            && $tracer->isSpanRecordingEnabled()
            && $parent !== null
            && ! $parent->isFinished()) {
            $desc = $method.' '.$url;
            if (strlen($desc) > 512) {
                $desc = substr($desc, 0, 509).'…';
            }
            $span = $parent->startChild(SpanOperation::HTTP_CLIENT, $desc);
        }

        $response = $this->inner->request($method, $url, $options);

        if ($span === null) {
            return $response;
        }

        return new SymfonyTracedResponseWrapper($response, $span);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $normalized = self::unwrapResponsesForInnerClient($responses);

        return $this->inner->stream($normalized, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $traceHeaders  {@see Tracer::outgoingTraceHeaders()}
     * @return array<string, mixed>
     */
    private static function mergeTraceHeaders(array $options, array $traceHeaders): array
    {
        $headers = $options['headers'] ?? [];
        if (! is_array($headers)) {
            $headers = [];
        }
        $tp = TraceWireHeaders::HTTP_TRACEPARENT;
        $headers[$tp] = $traceHeaders[$tp];
        $headers[TraceWireHeaders::HTTP_BAGGAGE] = $traceHeaders[TraceWireHeaders::HTTP_BAGGAGE];
        $options['headers'] = $headers;

        return $options;
    }

    private static function unwrapResponsesForInnerClient(ResponseInterface|iterable $responses): ResponseInterface|iterable
    {
        if ($responses instanceof SymfonyTracedResponseWrapper) {
            return $responses->unwrap();
        }
        if (! is_iterable($responses)) {
            return $responses;
        }

        $out = [];
        foreach ($responses as $k => $r) {
            $out[$k] = $r instanceof SymfonyTracedResponseWrapper ? $r->unwrap() : $r;
        }

        return $out;
    }
}

/**
 * @internal
 */
final class SymfonyTracedResponseWrapper implements ResponseInterface
{
    private bool $spanFinalized = false;

    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly ?Span $span,
    ) {}

    public function unwrap(): ResponseInterface
    {
        return $this->inner;
    }

    public function getStatusCode(): int
    {
        if ($this->span !== null && ! $this->span->isFinished() && ! $this->spanFinalized) {
            $this->spanFinalized = true;
            try {
                $code = $this->inner->getStatusCode();
                $this->span->setData(['http.status_code' => $code]);
                if ($code >= 500) {
                    $this->span->setStatus('internal_error');
                }
                $this->span->finish();

                return $code;
            } catch (Throwable $e) {
                $this->span->setStatus('internal_error');
                $this->span->setData(['error' => $e->getMessage()]);
                $this->span->finish();
                throw $e;
            }
        }

        return $this->inner->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        $this->finishSpanAfterStatusResolved();

        return $this->inner->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        $this->finishSpanAfterStatusResolved();

        return $this->inner->getContent($throw);
    }

    public function toArray(bool $throw = true): array
    {
        $this->finishSpanAfterStatusResolved();

        return $this->inner->toArray($throw);
    }

    public function cancel(): void
    {
        $this->finishSpanAfterStatusResolved();
        $this->inner->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->inner->getInfo($type);
    }

    /**
     * Finishes the span using status code when the caller resolves the response through another API.
     */
    private function finishSpanAfterStatusResolved(): void
    {
        if ($this->span === null || $this->span->isFinished()) {
            return;
        }
        if ($this->spanFinalized) {
            return;
        }
        $this->spanFinalized = true;

        try {
            $code = $this->inner->getStatusCode();
            $this->span->setData(['http.status_code' => $code]);
            if ($code >= 500) {
                $this->span->setStatus('internal_error');
            }
        } catch (Throwable $e) {
            $this->span->setStatus('internal_error');
            $this->span->setData(['error' => $e->getMessage()]);
        }
        if (! $this->span->isFinished()) {
            $this->span->finish();
        }
    }
}
