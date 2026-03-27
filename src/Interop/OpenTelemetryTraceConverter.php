<?php

declare(strict_types=1);

namespace Lookout\Tracing\Interop;

/**
 * Maps OTLP-style JSON span shapes (e.g. {@code resourceSpans} export) to Lookout trace ingest bodies.
 *
 * Does not depend on the OpenTelemetry PHP SDK — pass decoded JSON arrays from your exporter or tests.
 *
 * @see https://opentelemetry.io/docs/specs/otlp/
 */
final class OpenTelemetryTraceConverter
{
    /**
     * Convert an OTLP JSON export object to a Lookout {@code POST /api/ingest/trace} body.
     *
     * Expects top-level {@code resourceSpans} (array). Uses the first span’s {@code traceId} as the batch trace id.
     *
     * @param  array<string, mixed>  $otlpJson  Root object with {@code resourceSpans}
     * @return array<string, mixed>|null {@code trace_id}, {@code spans}, optional {@code transaction}
     */
    public static function toLookoutIngestBody(array $otlpJson): ?array
    {
        $rows = self::collectSpans($otlpJson);
        if ($rows === []) {
            return null;
        }

        $traceId = $rows[0]['_trace_hex'];
        $lookoutSpans = [];
        $rootName = null;

        foreach ($rows as $row) {
            $span = $row['span'];
            if (! is_array($span)) {
                continue;
            }
            $mapped = self::mapSpan($span);
            if ($mapped === null) {
                continue;
            }
            $lookoutSpans[] = $mapped;
            if (($mapped['parent_span_id'] ?? null) === null && $rootName === null) {
                $rootName = $mapped['description'] ?? $mapped['op'] ?? null;
            }
        }

        if ($lookoutSpans === []) {
            return null;
        }

        $out = [
            'trace_id' => strtolower($traceId),
            'spans' => $lookoutSpans,
        ];
        if (is_string($rootName) && $rootName !== '') {
            $out['transaction'] = $rootName;
        }

        return $out;
    }

    /**
     * @return list<array{span: array<string, mixed>, _trace_hex: string}>
     */
    private static function collectSpans(array $otlpJson): array
    {
        $resourceSpans = $otlpJson['resourceSpans'] ?? null;
        if (! is_array($resourceSpans)) {
            return [];
        }

        $out = [];
        foreach ($resourceSpans as $rs) {
            if (! is_array($rs)) {
                continue;
            }
            $scopes = $rs['scopeSpans'] ?? null;
            if (! is_array($scopes)) {
                continue;
            }
            foreach ($scopes as $ss) {
                if (! is_array($ss)) {
                    continue;
                }
                $spans = $ss['spans'] ?? null;
                if (! is_array($spans)) {
                    continue;
                }
                foreach ($spans as $span) {
                    if (! is_array($span)) {
                        continue;
                    }
                    $tid = self::normalizeTraceId($span['traceId'] ?? '');
                    if ($tid === null) {
                        continue;
                    }
                    $out[] = ['span' => $span, '_trace_hex' => $tid];
                }
            }
        }

        return $out;
    }

    private static function normalizeTraceId(mixed $traceId): ?string
    {
        if (! is_string($traceId) || $traceId === '') {
            return null;
        }
        $clean = strtolower(preg_replace('/[^a-f0-9]/', '', $traceId) ?? '');
        if (strlen($clean) === 32) {
            return $clean;
        }
        if (strlen($traceId) === 16) {
            return bin2hex($traceId);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>|null
     */
    private static function mapSpan(array $span): ?array
    {
        $spanId = self::normalizeSpanId($span['spanId'] ?? '');
        if ($spanId === null) {
            return null;
        }

        $parent = isset($span['parentSpanId']) ? self::normalizeSpanId($span['parentSpanId']) : null;

        $name = isset($span['name']) && is_string($span['name']) ? $span['name'] : 'span';
        $kind = isset($span['kind']) ? (int) $span['kind'] : 0;
        $op = self::kindToOp($kind);

        $startNs = $span['startTimeUnixNano'] ?? null;
        $endNs = $span['endTimeUnixNano'] ?? null;
        $start = self::nanoToUnix($startNs);
        if ($start === null) {
            return null;
        }
        $end = self::nanoToUnix($endNs);
        $durationMs = null;
        if ($end !== null) {
            $durationMs = (int) round(($end - $start) * 1000);
        }

        $row = [
            'span_id' => $spanId,
            'parent_span_id' => $parent,
            'op' => $op,
            'description' => strlen($name) > 512 ? substr($name, 0, 512).'…' : $name,
            'start_timestamp' => $start,
            'duration_ms' => $durationMs,
            'status' => null,
            'data' => [],
        ];
        if ($end !== null) {
            $row['end_timestamp'] = $end;
        }

        return $row;
    }

    private static function normalizeSpanId(mixed $spanId): ?string
    {
        if (! is_string($spanId) || $spanId === '') {
            return null;
        }
        $clean = strtolower(preg_replace('/[^a-f0-9]/', '', $spanId) ?? '');
        if (strlen($clean) >= 16) {
            return substr($clean, 0, 16);
        }
        if (strlen($spanId) === 8) {
            return bin2hex($spanId);
        }

        return null;
    }

    private static function nanoToUnix(mixed $nano): ?float
    {
        if ($nano === null) {
            return null;
        }
        if (is_string($nano) && ctype_digit($nano)) {
            $nano = (float) $nano;
        }
        if (! is_int($nano) && ! is_float($nano)) {
            return null;
        }
        $n = (float) $nano;

        return $n / 1_000_000_000.0;
    }

    private static function kindToOp(int $kind): string
    {
        return match ($kind) {
            2 => 'http.server',
            3 => 'http.client',
            4 => 'queue.publish',
            5 => 'queue.process',
            1 => 'internal',
            default => 'internal',
        };
    }
}
