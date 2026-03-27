<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * sentry-trace HTTP header: "{trace_id}-{span_id}-{sampled}"
 *
 * trace_id: 32 hex chars, span_id: 16 hex chars, sampled: 0, 1, or ? (optional / omitted).
 *
 * @see https://docs.sentry.io/platforms/php/tracing/trace-propagation/
 */
final class SentryTraceHeader
{
    /**
     * @return array{trace_id: string, span_id: string, sampled: bool|null}|null
     *                                                                           sampled null means "unknown" (? or missing segment)
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);
        if ($t === '') {
            return null;
        }

        if (preg_match('/^([a-f0-9]{32})-([a-f0-9]{16})(?:-([01?]))?$/i', $t, $m)) {
            $sampled = null;
            if (isset($m[3])) {
                $sampled = match ($m[3]) {
                    '1' => true,
                    '0' => false,
                    default => null,
                };
            }

            return [
                'trace_id' => strtolower($m[1]),
                'span_id' => strtolower($m[2]),
                'sampled' => $sampled,
            ];
        }

        return null;
    }

    /**
     * @param  bool|null  $sampled  true/false for 1/0; null uses "?" (deferred / unknown)
     */
    public static function format(string $traceId, string $spanId, ?bool $sampled = true): string
    {
        $traceId = strtolower($traceId);
        $spanId = strtolower($spanId);
        $flag = match ($sampled) {
            true => '1',
            false => '0',
            null => '?',
        };

        return $traceId.'-'.$spanId.'-'.$flag;
    }

    /**
     * Two-part form (trace_id-span_id) for clients that omit sampling.
     */
    public static function formatWithoutSamplingFlag(string $traceId, string $spanId): string
    {
        return strtolower($traceId).'-'.strtolower($spanId);
    }
}
