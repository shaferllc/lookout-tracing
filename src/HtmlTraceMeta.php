<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Inject {@code <meta>} tags for browser SDKs (same pattern as Sentry PHP docs).
 *
 * @see https://docs.sentry.io/platforms/php/tracing/trace-propagation/
 */
final class HtmlTraceMeta
{
    public static function render(?Tracer $tracer = null): string
    {
        $tracer ??= Tracer::instance();
        $trace = htmlspecialchars($tracer->traceparent(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $baggage = htmlspecialchars($tracer->baggageHeader(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<meta name="sentry-trace" content="'.$trace.'"/>'."\n"
            .'<meta name="baggage" content="'.$baggage.'"/>';
    }
}
