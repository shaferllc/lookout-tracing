<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * **No extension required:** record coarse stack samples by calling {@see pulse()} from your own
 * loops or middleware (cooperative sampling). Lower overhead than Excimer but requires code hooks.
 *
 * For production web requests, prefer {@see ExcimerExporter} when the Excimer extension is available.
 */
final class ManualPulseSampler
{
    /** @var list<array{t: float, frames: list<array<string, mixed>}> */
    private array $samples = [];

    private float $start;

    public function __construct(private int $maxBacktraceFrames = 25)
    {
        $this->start = microtime(true);
    }

    public function pulse(): void
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxBacktraceFrames);
        $frames = [];
        foreach ($bt as $f) {
            $frames[] = [
                'file' => $f['file'] ?? '',
                'line' => $f['line'] ?? 0,
                'function' => $f['function'] ?? '',
                'class' => $f['class'] ?? null,
                'type' => $f['type'] ?? null,
            ];
        }
        $this->samples[] = ['t' => microtime(true), 'frames' => $frames];
    }

    /**
     * @param  array<string, mixed>  $context  trace_id, transaction, environment, release
     * @return array<string, mixed>
     */
    public function toIngestPayload(array $context = []): array
    {
        $payload = [
            'started_at' => $this->start,
            'ended_at' => microtime(true),
            'sample_count' => count($this->samples),
            'samples' => $this->samples,
        ];

        return array_filter(array_merge([
            'agent' => 'php.manual_pulse',
            'format' => 'lookout.samples.v1',
            'data' => $payload,
        ], $context), fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
