<?php

declare(strict_types=1);

namespace Lookout\Tracing\Metrics;

/**
 * Wall-clock timer started via {@see MetricsIngestClient::start()} or {@see MetricBuilder::start()}.
 */
final class MetricTimer
{
    private bool $finished = false;

    /**
     * @param  array{
     *     attributes: array<string, mixed>,
     *     unit: ?string,
     *     trace_id: ?string,
     *     timestamp: int|float|string|null,
     *     environment: ?string,
     *     release: ?string,
     * }  $context
     */
    public function __construct(
        private readonly MetricsIngestClient $client,
        private readonly string $name,
        private array $context,
        private readonly int $startNs,
    ) {}

    /**
     * Record elapsed time as a distribution sample (milliseconds by default).
     *
     * @param  array<string, mixed>  $extraAttributes  Merged onto attributes from {@see start()}.
     */
    public function stop(array $extraAttributes = []): float
    {
        if ($this->finished) {
            return 0.0;
        }
        $this->finished = true;

        $durationMs = (hrtime(true) - $this->startNs) / 1e6;
        if ($extraAttributes !== []) {
            $this->context['attributes'] = array_merge($this->context['attributes'], $extraAttributes);
        }
        if ($this->context['unit'] === null || $this->context['unit'] === '') {
            $this->context['unit'] = MetricUnit::millisecond();
        }

        $this->client->recordSample('distribution', $this->name, $durationMs, $this->context);

        return $durationMs;
    }

    /**
     * Discard the timer without recording a sample.
     */
    public function cancel(): void
    {
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }
}
