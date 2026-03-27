<?php

declare(strict_types=1);

namespace Lookout\Tracing;

final class Span
{
    private ?float $endUnix = null;

    private bool $finished = false;

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

    public function startChild(string $op, ?string $description = null): self
    {
        if ($this->finished) {
            throw new \RuntimeException('Cannot start a child on a finished span.');
        }

        $child = new self(
            $this->tracer,
            $this->traceId,
            Id::spanId(),
            $this->spanId,
            $op,
            $description,
            microtime(true),
        );
        $this->tracer->pushSpan($child);

        return $child;
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
