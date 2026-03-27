<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Sentry-style hub: continue incoming trace, run transactions/spans, emit sentry-trace + baggage for outbound calls.
 *
 * Optional flush to Lookout {@see flush()} posts to {@code POST /api/ingest/trace}.
 */
final class Tracer
{
    private static ?self $instance = null;

    /** @var list<Span> */
    private array $spanStack = [];

    /** @var list<array<string, mixed>> */
    private array $finishedRecords = [];

    private string $traceId = '';

    /** Parent span id from upstream sentry-trace (caller). */
    private ?string $remoteParentSpanId = null;

    /** @var array<string, string> */
    private array $baggageEntries = [];

    private ?bool $defaultSampled = true;

    private ?string $defaultOutgoingSpanId = null;

    private ?string $rootTransactionName = null;

    /** @var array<string, mixed> */
    private array $config = [];

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function resetForTesting(): void
    {
        self::$instance = null;
    }

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     ingest_trace_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null
     * }  $config
     */
    public function configure(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * Extract upstream trace (same idea as {@see continueTrace()}).
     */
    public function continueTrace(?string $sentryTraceHeader, ?string $baggageHeader): self
    {
        $this->spanStack = [];
        $this->finishedRecords = [];
        $this->rootTransactionName = null;

        $parsed = SentryTraceHeader::parse($sentryTraceHeader);
        $incomingBaggage = Baggage::parse($baggageHeader);

        if ($parsed !== null) {
            $this->traceId = $parsed['trace_id'];
            $this->remoteParentSpanId = $parsed['span_id'];
            $this->defaultSampled = $parsed['sampled'];
            $this->defaultOutgoingSpanId = $parsed['span_id'];
        } else {
            $this->traceId = Id::traceId();
            $this->remoteParentSpanId = null;
            $this->defaultSampled = true;
            $this->defaultOutgoingSpanId = Id::spanId();
        }

        $this->baggageEntries = $incomingBaggage;

        return $this;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public function mergeBaggage(array $entries): self
    {
        $this->baggageEntries = Baggage::merge($this->baggageEntries, $entries);

        return $this;
    }

    public function startTransaction(string $name, string $op = 'http.server'): Span
    {
        $this->ensureInitialized();
        $span = new Span(
            $this,
            $this->traceId,
            Id::spanId(),
            $this->remoteParentSpanId,
            $op,
            $name,
            microtime(true),
        );
        $this->rootTransactionName = $name;
        $this->pushSpan($span);

        return $span;
    }

    public function getCurrentSpan(): ?Span
    {
        if ($this->spanStack === []) {
            return null;
        }

        return $this->spanStack[array_key_last($this->spanStack)];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function trace(callable $callback, string $op, ?string $description = null): mixed
    {
        $parent = $this->getCurrentSpan();
        if ($parent === null) {
            throw new \RuntimeException('lookout/tracing: trace() requires an active transaction or span.');
        }
        $span = $parent->startChild($op, $description);
        try {
            return $callback();
        } finally {
            $span->finish();
        }
    }

    /**
     * sentry-trace header value for outbound HTTP (current span or passthrough).
     */
    public function traceparent(): string
    {
        $this->ensureInitialized();
        $spanId = $this->currentSpanIdForPropagation();

        return SentryTraceHeader::format($this->traceId, $spanId, $this->defaultSampled);
    }

    public function baggageHeader(): string
    {
        $this->ensureInitialized();
        $merged = $this->baggageEntries;
        $merged['sentry-trace_id'] = $this->traceId;
        if ($this->rootTransactionName !== null && $this->rootTransactionName !== '') {
            $merged['sentry-transaction'] = $this->rootTransactionName;
        }

        return Baggage::build($merged);
    }

    /**
     * @return array{sentry-trace: string, baggage: string}
     */
    public function outgoingTraceHeaders(): array
    {
        $this->ensureInitialized();

        return [
            'sentry-trace' => $this->traceparent(),
            'baggage' => $this->baggageHeader(),
        ];
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * @return array<string, string>
     */
    public function baggageEntries(): array
    {
        return $this->baggageEntries;
    }

    /**
     * Fields for Lookout error ingest body (trace_id, span_id, parent_span_id, transaction).
     *
     * @return array{trace_id: string, span_id: string, parent_span_id: ?string, transaction: ?string}
     */
    public function errorIngestTraceFields(): array
    {
        $this->ensureInitialized();
        $current = $this->getCurrentSpan();

        return [
            'trace_id' => $this->traceId,
            'span_id' => $current?->spanId ?? $this->currentSpanIdForPropagation(),
            'parent_span_id' => $current?->parentSpanId,
            'transaction' => $this->rootTransactionName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTraceIngestBody(): array
    {
        $this->ensureInitialized();
        $spans = $this->finishedRecords;
        if ($spans === []) {
            return [];
        }

        return [
            'trace_id' => $this->traceId,
            'transaction' => $this->rootTransactionName,
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->config['release'] ?? null,
            'spans' => $spans,
        ];
    }

    /**
     * POST recorded spans to Lookout. Returns false if nothing to send or not configured.
     */
    public function flush(): bool
    {
        $body = $this->buildTraceIngestBody();
        if ($body === [] || empty($this->config['api_key'])) {
            return false;
        }

        $base = rtrim((string) ($this->config['base_uri'] ?? ''), '/');
        $path = $this->config['ingest_trace_path'] ?? '/api/ingest/trace';
        $path = '/'.ltrim((string) $path, '/');
        $url = $base.$path;

        return HttpTransport::postJson($url, (string) $this->config['api_key'], $body);
    }

    public function clearFinishedSpans(): void
    {
        $this->finishedRecords = [];
    }

    /** @internal */
    public function pushSpan(Span $span): void
    {
        $this->spanStack[] = $span;
        $this->defaultOutgoingSpanId = $span->spanId;
    }

    /** @internal */
    public function popSpanIfCurrent(Span $span): void
    {
        if ($this->spanStack === []) {
            return;
        }
        $last = $this->spanStack[array_key_last($this->spanStack)];
        if ($last === $span) {
            array_pop($this->spanStack);
        }
        if ($this->spanStack !== []) {
            $top = $this->spanStack[array_key_last($this->spanStack)];
            $this->defaultOutgoingSpanId = $top->spanId;

            return;
        }
        // After the active stack unwinds, keep propagating the last finished local span.
        $this->defaultOutgoingSpanId = $span->spanId;
    }

    /** @internal */
    public function recordSpan(Span $span): void
    {
        $end = $span->endUnix();
        if ($end === null) {
            return;
        }
        $row = [
            'span_id' => $span->spanId,
            'parent_span_id' => $span->parentSpanId,
            'op' => $span->op,
            'description' => $span->description,
            'start_timestamp' => $span->startUnix(),
            'end_timestamp' => $end,
            'status' => $span->status(),
        ];
        $data = $span->data();
        if ($data !== []) {
            $row['data'] = $data;
        }
        $this->finishedRecords[] = $row;
    }

    private function currentSpanIdForPropagation(): string
    {
        $current = $this->getCurrentSpan();
        if ($current !== null) {
            return $current->spanId;
        }

        return $this->defaultOutgoingSpanId ?? Id::spanId();
    }

    private function ensureInitialized(): void
    {
        if ($this->traceId === '') {
            $this->continueTrace(null, null);
        }
    }
}
