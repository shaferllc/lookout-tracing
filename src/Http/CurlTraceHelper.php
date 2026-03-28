<?php

declare(strict_types=1);

namespace Lookout\Tracing\Http;

use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;
use Throwable;

/**
 * Trace propagation and optional {@code http.client} spans for stacks that use PHP's cURL API directly.
 *
 * PHP cannot read an existing {@code CURLOPT_HTTPHEADER} list back from a handle, so callers must pass
 * the full header list they intend to set (e.g. merge {@see appendTraceHeaderLines()} before
 * {@see curl_setopt}).
 */
final class CurlTraceHelper
{
    /**
     * @param  list<string>  $headerLines  {@code Name: value} lines for {@see CURLOPT_HTTPHEADER}
     * @return list<string>
     */
    public static function appendTraceHeaderLines(array $headerLines, ?Tracer $tracer = null): array
    {
        $tracer ??= Tracer::instance();
        $h = $tracer->outgoingTraceHeaders();
        $tp = TraceWireHeaders::HTTP_TRACEPARENT;
        $headerLines[] = $tp.': '.$h[$tp];
        $headerLines[] = TraceWireHeaders::HTTP_BAGGAGE.': '.$h[TraceWireHeaders::HTTP_BAGGAGE];

        return $headerLines;
    }

    /**
     * Runs {@see curl_exec} with an {@code http.client} span when performance + outbound HTTP spans are enabled.
     *
     * @param  \CurlHandle|resource  $handle
     */
    public static function tracedExec($handle, string $description, ?Tracer $tracer = null): bool|string
    {
        $tracer ??= Tracer::instance();
        $parent = $tracer->getCurrentSpan();
        $span = null;
        if ($tracer->isPerformanceEnabled()
            && $tracer->shouldRecordHttpClientSpans()
            && $tracer->isSpanRecordingEnabled()
            && $parent !== null
            && ! $parent->isFinished()) {
            $desc = strlen($description) > 512 ? substr($description, 0, 509).'…' : $description;
            $span = $parent->startChild(SpanOperation::HTTP_CLIENT, $desc);
        }

        try {
            $result = curl_exec($handle);
        } catch (Throwable $e) {
            if ($span !== null && ! $span->isFinished()) {
                $span->setStatus('internal_error');
                $span->setData(['error' => $e->getMessage()]);
                $span->finish();
            }
            throw $e;
        }

        if ($span !== null && ! $span->isFinished()) {
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($code > 0) {
                $span->setData(['http.status_code' => $code]);
            }
            if ($code >= 500) {
                $span->setStatus('internal_error');
            }
            $err = curl_error($handle);
            if (is_string($err) && $err !== '' && $result === false) {
                $span->setStatus('internal_error');
                $span->setData(['error' => $err]);
            }
            $span->finish();
        }

        return $result;
    }
}
