<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * Builds first-party profile bodies for {@see ProfileClient::sendProfile()}.
 *
 * Use {@see FORMAT_AGGREGATE} with agent {@code lookout} for pre-aggregated hotspots.
 * Time-series cooperative sampling stays on {@see ManualPulseSampler} ({@see FORMAT_SAMPLES}).
 */
final class LookoutProfileV1Payload
{
    public const FORMAT_AGGREGATE = 'lookout.v1';

    public const FORMAT_SAMPLES = 'lookout.samples.v1';

    /**
     * @param  list<array{file: string, line: int, samples: int}>  $frames
     * @param  array<string, mixed>  $meta  Optional small metadata map (stored under {@code data.meta}).
     * @param  array<string, mixed>  $context  trace_id, transaction, environment, release, etc.
     * @return array<string, mixed>
     */
    public static function aggregateIngestBody(array $frames, array $meta = [], array $context = []): array
    {
        $data = [
            'schema_version' => 1,
            'frames' => array_values($frames),
        ];
        if ($meta !== []) {
            $data['meta'] = $meta;
        }

        $body = array_merge([
            'agent' => 'lookout',
            'format' => self::FORMAT_AGGREGATE,
            'data' => $data,
        ], $context);

        return array_filter($body, fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
