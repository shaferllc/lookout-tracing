<?php

declare(strict_types=1);

namespace Lookout\Tracing\Interop;

/**
 * Maps OTLP JSON trace payloads (Protobuf JSON encoding) to Lookout trace ingest / store payloads.
 *
 * Does not depend on the OpenTelemetry PHP SDK — pass decoded JSON arrays from your exporter or tests.
 *
 * @see https://opentelemetry.io/docs/specs/otlp/
 */
final class OpenTelemetryTraceConverter
{
    private const MAX_ATTR_KEYS_PER_SPAN = 64;

    private const MAX_ATTR_STRING_LEN = 1024;

    /**
     * Convert an OTLP JSON export object to a Lookout {@code POST /api/ingest/trace} body.
     *
     * Expects top-level {@code resourceSpans} (array). When the export contains **multiple** trace ids,
     * returns {@code null} — use {@see toJobPayloads()} for multi-trace batches.
     *
     * @param  array<string, mixed>  $otlpJson  Root object with {@code resourceSpans}
     * @return array<string, mixed>|null {@code trace_id}, {@code spans}, optional {@code transaction}, {@code environment}, {@code release}
     */
    public static function toLookoutIngestBody(array $otlpJson): ?array
    {
        $payloads = self::toJobPayloads($otlpJson);
        if (count($payloads) !== 1) {
            return null;
        }

        return self::jobPayloadToLookoutIngestBody($payloads[0]);
    }

    /**
     * One store payload per distinct {@code traceId} (OTLP may batch many traces in one export).
     *
     * Each payload matches the shape consumed by Lookout's trace persistence job
     * ({@code trace_id}, {@code transaction}, {@code environment}, {@code release}, {@code spans} with
     * {@code start_unix}, {@code end_unix}, {@code duration_ms}, etc.).
     *
     * @param  array<string, mixed>  $otlpJson
     * @return list<array{
     *     trace_id: string,
     *     transaction: ?string,
     *     environment: ?string,
     *     release: ?string,
     *     commit_sha: ?string,
     *     deployed_at: ?string,
     *     spans: list<array<string, mixed>>
     * }>
     */
    public static function toJobPayloads(array $otlpJson): array
    {
        $byTrace = self::spansByTraceId($otlpJson);
        $out = [];
        foreach ($byTrace as $traceHex => $items) {
            $payload = self::buildJobPayloadForTrace($traceHex, $items);
            if ($payload !== null) {
                $out[] = $payload;
            }
        }

        return $out;
    }

    /**
     * Lookout-native ingest body (API JSON) → OTLP JSON {@code resourceSpans} (single resource, single scope).
     * Useful for forwarding to OTLP collectors or round-trip tests.
     *
     * @param  array<string, mixed>  $lookout  {@code trace_id}, {@code spans} (API shape: {@code start_timestamp}, optional {@code end_timestamp} / {@code duration_ms})
     * @return array<string, mixed>
     */
    public static function fromLookoutIngestBody(array $lookout): array
    {
        $traceId = isset($lookout['trace_id']) && is_string($lookout['trace_id'])
            ? strtolower(preg_replace('/[^a-f0-9]/', '', $lookout['trace_id']) ?? '') : '';
        if (strlen($traceId) > 32) {
            $traceId = substr($traceId, 0, 32);
        }
        if (strlen($traceId) < 32) {
            $traceId = str_pad($traceId, 32, '0');
        }

        $env = isset($lookout['environment']) && is_string($lookout['environment']) ? $lookout['environment'] : null;
        $rel = isset($lookout['release']) && is_string($lookout['release']) ? $lookout['release'] : null;

        $resourceAttrs = [];
        if ($env !== null && $env !== '') {
            $resourceAttrs[] = ['key' => 'deployment.environment', 'value' => ['stringValue' => substr($env, 0, 256)]];
        }
        if ($rel !== null && $rel !== '') {
            $resourceAttrs[] = ['key' => 'service.version', 'value' => ['stringValue' => substr($rel, 0, 256)]];
        }

        $spansOut = [];
        $spanList = $lookout['spans'] ?? [];
        if (is_array($spanList)) {
            foreach ($spanList as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $mapped = self::lookoutApiSpanToOtlpSpan($traceId, $s);
                if ($mapped !== null) {
                    $spansOut[] = $mapped;
                }
            }
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $resourceAttrs,
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'lookout',
                                'version' => '1.0.0',
                            ],
                            'spans' => $spansOut,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $jobPayload  Output of {@see toJobPayloads()}[i]
     * @return array<string, mixed>
     */
    public static function jobPayloadToLookoutIngestBody(array $jobPayload): array
    {
        $spans = [];
        foreach ($jobPayload['spans'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $s = [
                'span_id' => $row['span_id'],
                'parent_span_id' => $row['parent_span_id'],
                'op' => $row['op'],
                'description' => $row['description'] ?? null,
                'start_timestamp' => $row['start_unix'],
                'status' => $row['status'] ?? null,
                'data' => $row['data'] ?? null,
            ];
            if (isset($row['end_unix']) && $row['end_unix'] !== null) {
                $s['end_timestamp'] = $row['end_unix'];
            }
            if (isset($row['duration_ms']) && $row['duration_ms'] !== null) {
                $s['duration_ms'] = $row['duration_ms'];
            }
            $spans[] = $s;
        }

        $out = [
            'trace_id' => $jobPayload['trace_id'],
            'spans' => $spans,
        ];
        foreach (['transaction', 'environment', 'release', 'commit_sha', 'deployed_at'] as $k) {
            if (! empty($jobPayload[$k])) {
                $out[$k] = $jobPayload[$k];
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<array{span: array<string, mixed>, resource: ?array<string, mixed>}>>
     */
    private static function spansByTraceId(array $otlpJson): array
    {
        $resourceSpans = $otlpJson['resourceSpans'] ?? null;
        if (! is_array($resourceSpans)) {
            return [];
        }

        $byTrace = [];
        foreach ($resourceSpans as $rs) {
            if (! is_array($rs)) {
                continue;
            }
            $resource = isset($rs['resource']) && is_array($rs['resource']) ? $rs['resource'] : null;
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
                    if (! isset($byTrace[$tid])) {
                        $byTrace[$tid] = [];
                    }
                    $byTrace[$tid][] = ['span' => $span, 'resource' => $resource];
                }
            }
        }

        return $byTrace;
    }

    /**
     * @param  list<array{span: array<string, mixed>, resource: ?array<string, mixed>}>  $items
     * @return ?array<string, mixed>
     */
    private static function buildJobPayloadForTrace(string $traceHex, array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $env = null;
        $rel = null;
        $firstRes = $items[0]['resource'] ?? null;
        if ($firstRes !== null) {
            $meta = self::resourceEnvironmentRelease($firstRes);
            $env = $meta['environment'];
            $rel = $meta['release'];
        }

        $jobSpans = [];
        $rootName = null;
        foreach ($items as $item) {
            $mapped = self::mapOtlpSpanToJobRow($item['span']);
            if ($mapped === null) {
                continue;
            }
            $jobSpans[] = $mapped;
            if (($mapped['parent_span_id'] ?? null) === null && $rootName === null) {
                $rootName = $mapped['description'] ?? $mapped['op'] ?? null;
            }
        }

        if ($jobSpans === []) {
            return null;
        }

        return [
            'trace_id' => strtolower($traceHex),
            'transaction' => is_string($rootName) && $rootName !== '' ? $rootName : null,
            'environment' => $env,
            'release' => $rel,
            'commit_sha' => null,
            'deployed_at' => null,
            'spans' => $jobSpans,
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{environment: ?string, release: ?string}
     */
    private static function resourceEnvironmentRelease(array $resource): array
    {
        $flat = self::flattenOtlpAttributeList($resource['attributes'] ?? null);

        return [
            'environment' => isset($flat['deployment.environment']) && is_string($flat['deployment.environment'])
                ? substr($flat['deployment.environment'], 0, 64) : null,
            'release' => isset($flat['service.version']) && is_string($flat['service.version'])
                ? substr($flat['service.version'], 0, 128) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $span
     * @return ?array<string, mixed>
     */
    private static function mapOtlpSpanToJobRow(array $span): ?array
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

        $status = self::mapOtlpStatus($span['status'] ?? null);
        $data = self::spanAttributesToData($span['attributes'] ?? null);

        $row = [
            'span_id' => $spanId,
            'parent_span_id' => $parent,
            'op' => $op,
            'description' => strlen($name) > 512 ? substr($name, 0, 512).'…' : $name,
            'start_unix' => $start,
            'end_unix' => $end,
            'duration_ms' => $durationMs,
            'status' => $status,
            'data' => $data !== [] ? $data : null,
        ];

        return $row;
    }

    private static function mapOtlpStatus(mixed $status): ?string
    {
        if (! is_array($status)) {
            return null;
        }
        $code = (int) ($status['code'] ?? 0);

        return $code === 2 ? 'internal_error' : null;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private static function spanAttributesToData(mixed $attributes): array
    {
        $flat = self::flattenOtlpAttributeList($attributes);
        $out = [];
        $n = 0;
        foreach ($flat as $k => $v) {
            if ($n >= self::MAX_ATTR_KEYS_PER_SPAN) {
                break;
            }
            if (! is_string($k) || $k === '') {
                continue;
            }
            $k = substr($k, 0, 256);
            if (is_string($v)) {
                $out[$k] = strlen($v) > self::MAX_ATTR_STRING_LEN
                    ? substr($v, 0, self::MAX_ATTR_STRING_LEN).'…' : $v;
            } elseif (is_int($v) || is_float($v) || is_bool($v)) {
                $out[$k] = $v;
            }
            $n++;
        }

        return $out;
    }

    /**
     * @param  mixed  $attributes  OTLP JSON: repeated {@code KeyValue} or array of maps
     * @return array<string, string|int|float|bool>
     */
    private static function flattenOtlpAttributeList(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $out = [];
        foreach ($attributes as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = $item['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $value = $item['value'] ?? null;
            if (! is_array($value)) {
                continue;
            }
            $parsed = self::parseOtlpAnyValue($value);
            if ($parsed !== null) {
                $out[$key] = $parsed;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $value  OTLP {@code AnyValue} JSON shape
     */
    private static function parseOtlpAnyValue(array $value): string|int|float|bool|null
    {
        if (array_key_exists('stringValue', $value)) {
            $s = $value['stringValue'];

            return is_string($s) || is_numeric($s) ? (string) $s : null;
        }
        if (array_key_exists('boolValue', $value)) {
            return (bool) $value['boolValue'];
        }
        if (array_key_exists('intValue', $value)) {
            $i = $value['intValue'];
            if (is_int($i)) {
                return $i;
            }
            if (is_float($i)) {
                return (int) $i;
            }
            if (is_string($i) && is_numeric($i)) {
                return (int) $i;
            }
        }
        if (array_key_exists('doubleValue', $value)) {
            return is_numeric($value['doubleValue']) ? (float) $value['doubleValue'] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $s  Lookout API span row
     * @return ?array<string, mixed> OTLP span object
     */
    private static function lookoutApiSpanToOtlpSpan(string $traceId32, array $s): ?array
    {
        $sid = isset($s['span_id']) ? self::normalizeSpanId($s['span_id']) : null;
        if ($sid === null) {
            return null;
        }
        $parent = isset($s['parent_span_id']) ? self::normalizeSpanId($s['parent_span_id']) : null;

        $op = isset($s['op']) && is_string($s['op']) ? $s['op'] : 'internal';
        $kind = self::opToKind($op);

        $startTs = $s['start_timestamp'] ?? null;
        $start = is_numeric($startTs) ? (float) $startTs : null;
        if ($start === null) {
            return null;
        }
        if ($start > 9999999999) {
            $start /= 1000.0;
        }

        $end = null;
        if (isset($s['end_timestamp']) && is_numeric($s['end_timestamp'])) {
            $end = (float) $s['end_timestamp'];
            if ($end > 9999999999) {
                $end /= 1000.0;
            }
        } elseif (isset($s['duration_ms']) && is_numeric($s['duration_ms'])) {
            $end = $start + ((int) $s['duration_ms']) / 1000.0;
        }

        $name = isset($s['description']) && is_string($s['description']) && $s['description'] !== ''
            ? $s['description'] : $op;

        $out = [
            'traceId' => $traceId32,
            'spanId' => $sid,
            'name' => substr($name, 0, 512),
            'kind' => $kind,
            'startTimeUnixNano' => (string) (int) round($start * 1_000_000_000),
        ];
        if ($parent !== null) {
            $out['parentSpanId'] = $parent;
        }
        if ($end !== null) {
            $out['endTimeUnixNano'] = (string) (int) round($end * 1_000_000_000);
        }

        $status = null;
        if (isset($s['status']) && $s['status'] === 'internal_error') {
            $status = ['code' => 2];
        } else {
            $status = ['code' => 1];
        }
        $out['status'] = $status;

        $data = $s['data'] ?? null;
        if (is_array($data) && $data !== []) {
            $attrs = [];
            foreach ($data as $dk => $dv) {
                if (! is_string($dk) || $dk === '' || count($attrs) >= self::MAX_ATTR_KEYS_PER_SPAN) {
                    break;
                }
                if (is_string($dv)) {
                    $attrs[] = ['key' => substr($dk, 0, 256), 'value' => ['stringValue' => substr($dv, 0, self::MAX_ATTR_STRING_LEN)]];
                } elseif (is_int($dv)) {
                    $attrs[] = ['key' => substr($dk, 0, 256), 'value' => ['intValue' => (string) $dv]];
                } elseif (is_float($dv)) {
                    $attrs[] = ['key' => substr($dk, 0, 256), 'value' => ['doubleValue' => $dv]];
                } elseif (is_bool($dv)) {
                    $attrs[] = ['key' => substr($dk, 0, 256), 'value' => ['boolValue' => $dv]];
                }
            }
            if ($attrs !== []) {
                $out['attributes'] = $attrs;
            }
        }

        return $out;
    }

    private static function opToKind(string $op): int
    {
        return match ($op) {
            'http.server' => 2,
            'http.client' => 3,
            'queue.publish' => 4,
            'queue.process' => 5,
            default => 1,
        };
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
