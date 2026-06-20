<?php

declare(strict_types=1);

namespace Lookout\Tracing\Metrics;

use Lookout\Tracing\Tracer;

/**
 * Fluent builder for a single metric sample with optional attributes, unit, trace, and timestamp.
 */
final class MetricBuilder
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?string $unit = null;

    private ?string $traceId = null;

    private int|float|string|null $timestamp = null;

    private ?string $environment = null;

    private ?string $release = null;

    public function __construct(
        private readonly MetricsIngestClient $client,
        private readonly string $name,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function attribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function unit(?string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function traceId(?string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }

    public function timestamp(int|float|string|null $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function environment(?string $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    public function release(?string $release): self
    {
        $this->release = $release;

        return $this;
    }

    public function count(float $delta = 1.0): void
    {
        $this->client->recordSample('counter', $this->name, $delta, $this->sampleContext());
    }

    public function gauge(float $value): void
    {
        $this->client->recordSample('gauge', $this->name, $value, $this->sampleContext());
    }

    public function distribution(float $value): void
    {
        $this->client->recordSample('distribution', $this->name, $value, $this->sampleContext());
    }

    /**
     * Start a wall-clock timer; call {@see MetricTimer::stop()} to record a distribution sample.
     */
    public function start(): MetricTimer
    {
        $context = $this->sampleContext();
        if ($context['trace_id'] === null) {
            $traceId = Tracer::instance()->traceId();
            if ($traceId !== '') {
                $context['trace_id'] = $traceId;
            }
        }

        return new MetricTimer($this->client, $this->name, $context, hrtime(true));
    }

    /**
     * @return array{
     *     attributes: array<string, mixed>,
     *     unit: ?string,
     *     trace_id: ?string,
     *     timestamp: int|float|string|null,
     *     environment: ?string,
     *     release: ?string,
     * }
     */
    public function sampleContext(): array
    {
        return [
            'attributes' => $this->attributes,
            'unit' => $this->unit,
            'trace_id' => $this->traceId,
            'timestamp' => $this->timestamp,
            'environment' => $this->environment,
            'release' => $this->release,
        ];
    }
}
