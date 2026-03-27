<?php

declare(strict_types=1);

namespace Lookout\Tracing;

final class Span
{
    private ?float $endUnix = null;

    private bool $finished = false;

    /** @var list<array{name: string, timestamp: float, attributes: array<string, mixed>}> */
    private array $spanEvents = [];

    public function __construct(
        private readonly Tracer $tracer,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $op,
        public readonly ?string $description,
        private readonly float $startUnix,
        /** @var array<string, mixed> */
        private array $data = [],
        private ?string $status = null,
    ) {}

    public function startChild(string $op, ?string $description = null, ?float $startUnixOverride = null): self
    {
        if ($this->finished) {
            throw new \RuntimeException('Cannot start a child on a finished span.');
        }

        $start = $startUnixOverride ?? microtime(true);
        $child = new self(
            $this->tracer,
            $this->traceId,
            Id::spanId(),
            $this->spanId,
            $op,
            $description,
            $start,
        );
        $this->tracer->pushSpan($child);

        return $child;
    }

    /**
     * OpenTelemetry-style instant event on this span (stored under {@code data.span_events} on ingest).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addSpanEvent(string $name, ?float $timestamp = null, array $attributes = []): self
    {
        if ($this->finished) {
            return $this;
        }
        if (! $this->tracer->canAddSpanEvent($this)) {
            return $this;
        }
        $this->spanEvents[] = [
            'name' => $name,
            'timestamp' => $timestamp ?? microtime(true),
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * @return list<array{name: string, timestamp: float, attributes: array<string, mixed>}>
     */
    public function spanEvents(): array
    {
        return $this->spanEvents;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function finish(?float $endUnix = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->endUnix = $endUnix ?? microtime(true);
        $this->tracer->invokeConfigureSpan($this);
        $this->finished = true;
        $this->tracer->recordSpan($this);
        $this->tracer->popSpanIfCurrent($this);
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function endUnix(): ?float
    {
        return $this->endUnix;
    }

    public function startUnix(): float
    {
        return $this->startUnix;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function status(): ?string
    {
        return $this->status;
    }
}
