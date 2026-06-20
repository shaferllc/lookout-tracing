<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

use Lookout\Tracing\Tracer;

/**
 * Fluent builder for manual profile uploads with transaction, trace, environment, release, and metadata.
 */
final class ProfileBuilder
{
    /** @var array<string, mixed> */
    private array $meta = [];

    private ?string $traceId = null;

    private ?string $environment = null;

    private ?string $release = null;

    public function __construct(
        private readonly ProfileIngestClient $client,
        private readonly string $transaction,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    public function attribute(string $key, mixed $value): self
    {
        $this->meta[$key] = $value;

        return $this;
    }

    public function traceId(?string $traceId): self
    {
        $this->traceId = $traceId;

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

    public function start(): ProfileTimer
    {
        $ctx = $this->context();
        $meta = is_array($ctx['meta'] ?? null) ? $ctx['meta'] : [];
        unset($ctx['meta']);

        return $this->client->start($this->transaction, $meta, $ctx);
    }

    /**
     * @param  list<array{file: string, line: int, samples: int}>  $frames
     */
    public function sendAggregate(array $frames): bool
    {
        return $this->client->sendAggregate($frames, $this->meta, $this->context());
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $traceId = $this->traceId;
        if ($traceId === null || $traceId === '') {
            $fromTracer = Tracer::instance()->traceId();
            $traceId = $fromTracer !== '' ? $fromTracer : null;
        }

        return array_filter([
            'transaction' => $this->transaction,
            'trace_id' => $traceId,
            'environment' => $this->environment,
            'release' => $this->release,
            'meta' => $this->meta !== [] ? $this->meta : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
