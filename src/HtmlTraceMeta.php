<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Inject {@code <meta>} tags so browser clients can read the active traceparent value and W3C baggage.
 */
final class HtmlTraceMeta
{
    public static function render(?Tracer $tracer = null): string
    {
        $tracer ??= Tracer::instance();
        $trace = htmlspecialchars($tracer->traceparent(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $baggage = htmlspecialchars($tracer->baggageHeader(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $traceId = htmlspecialchars($tracer->traceId(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tpName = htmlspecialchars(TraceWireHeaders::HTTP_TRACEPARENT, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<meta name="'.$tpName.'" content="'.$trace.'"/>'."\n"
            .'<meta name="baggage" content="'.$baggage.'"/>'."\n"
            .'<meta name="lookout-trace-id" content="'.$traceId.'"/>';
    }
}
