<?php

declare(strict_types=1);

namespace Lookout\Tracing;

use Lookout\Tracing\Performance\CompositeOrSampler;
use Lookout\Tracing\Performance\RateSampler;
use Lookout\Tracing\Performance\Sampler;
use Lookout\Tracing\Performance\TraceLimiter;

/**
 * Sentry-style hub: continue incoming trace, run transactions/spans, emit sentry-trace + baggage for outbound calls.
 *
 * Optional flush to Lookout {@see flush()} posts to {@code POST /api/ingest/trace}.
 *
 * Performance mode: root sampling, trace limits, span / span_event configurators.
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

    private TraceLimiter $traceLimiter;

    private ?Sampler $sampler = null;

    private bool $performanceEnabled = false;

    private bool $httpClientSpansEnabled = true;

    /** When false, spans are not appended to the export batch (trace still propagates). */
    private bool $spanRecordingEnabled = true;

    private int $recordedSpanCount = 0;

    /** @var ?callable(Span): void */
    private $configureSpan = null;

    /** @var ?callable(array<string, mixed>): (?array<string, mixed>) */
    private $configureSpanEvent = null;

    private ?string $autoManagedHttpSpanId = null;

    private ?string $autoManagedConsoleSpanId = null;

    private ?string $autoManagedQueueSpanId = null;

    private int $traceIngestMaxAttempts = 1;

    private int $traceIngestRetryDelayMs = 250;

    /** @var list<int> */
    private array $traceIngestRetryStatuses = [429];

    /**
     * When a job runs synchronously inside a request (or nested under another job), {@see continueTrace()}
     * clears the span stack; we stash the parent stack and trace fields here and restore after {@see finishAutoQueueTransaction()}.
     *
     * @var list<array{span_stack: list<Span>, trace: array<string, mixed>}>
     */
    private array $queueWorkerSuspendStack = [];

    /** When true, tail sampling must not drop this trace at flush (e.g. error report sent for same request). */
    private bool $traceTailExportForced = false;

    /** Upstream {@code sentry-trace} had explicit {@code sampled=1}. */
    private bool $upstreamSentrySampledTrue = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function resetForTesting(): void
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->traceLimiter = TraceLimiter::defaults();
    }

    /**
     * @param  array{
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     ingest_trace_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null,
     *     commit_sha?: string|null,
     *     deployed_at?: float|int|string|null,
     *     performance_enabled?: bool,
     *     http_client_spans?: bool,
     *     trace_limits?: array<string, mixed>|null,
     *     sampler?: Sampler|null,
     *     trace_ingest_max_attempts?: int,
     *     trace_ingest_retry_delay_ms?: int,
     *     trace_ingest_retry_statuses?: list<int>,
     *     tail_sampling?: array<string, mixed>,
     * }  $config
     */
    public function configure(array $config): self
    {
        if (array_key_exists('trace_ingest_max_attempts', $config)) {
            $this->traceIngestMaxAttempts = max(1, (int) $config['trace_ingest_max_attempts']);
            unset($config['trace_ingest_max_attempts']);
        }
        if (array_key_exists('trace_ingest_retry_delay_ms', $config)) {
            $this->traceIngestRetryDelayMs = max(0, (int) $config['trace_ingest_retry_delay_ms']);
            unset($config['trace_ingest_retry_delay_ms']);
        }
        if (isset($config['trace_ingest_retry_statuses']) && is_array($config['trace_ingest_retry_statuses'])) {
            $statuses = [];
            foreach ($config['trace_ingest_retry_statuses'] as $s) {
                $statuses[] = (int) $s;
            }
            $this->traceIngestRetryStatuses = $statuses !== [] ? array_values(array_unique($statuses)) : [429];
            unset($config['trace_ingest_retry_statuses']);
        }
        if (array_key_exists('http_client_spans', $config)) {
            $this->httpClientSpansEnabled = (bool) $config['http_client_spans'];
            unset($config['http_client_spans']);
        }
        if (array_key_exists('sampler', $config)) {
            $this->sampler = $config['sampler'] instanceof Sampler ? $config['sampler'] : null;
            unset($config['sampler']);
        }
        if (isset($config['performance_enabled'])) {
            $this->performanceEnabled = (bool) $config['performance_enabled'];
            unset($config['performance_enabled']);
        }
        if (isset($config['trace_limits']) && is_array($config['trace_limits'])) {
            $this->traceLimiter = TraceLimiter::fromConfig($config['trace_limits']);
            unset($config['trace_limits']);
        }
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * @param  callable(Span): void  $callback
     */
    public function configureSpans(callable $callback): self
    {
        $this->configureSpan = $callback;

        return $this;
    }

    /**
     * @param  callable(array<string, mixed>): (?array<string, mixed>)  $callback  Return null to drop the event
     */
    public function configureSpanEvents(callable $callback): self
    {
        $this->configureSpanEvent = $callback;

        return $this;
    }

    public function isPerformanceEnabled(): bool
    {
        return $this->performanceEnabled;
    }

    public function shouldRecordHttpClientSpans(): bool
    {
        return $this->httpClientSpansEnabled;
    }

    public function isSpanRecordingEnabled(): bool
    {
        return $this->spanRecordingEnabled;
    }

    /**
     * Ensures the current performance trace batch is exported on flush even if tail sampling would drop it.
     * Called when an error is reported to Lookout so traces align with issues.
     */
    public function markTraceMustExport(string $reason = 'manual'): void
    {
        unset($reason);
        $this->traceTailExportForced = true;
    }

    public function traceLimiter(): TraceLimiter
    {
        return $this->traceLimiter;
    }

    /** @internal */
    public function canAddSpanEvent(Span $span): bool
    {
        return count($span->spanEvents()) < $this->traceLimiter->maxSpanEventsPerSpan();
    }

    /** @internal */
    public function invokeConfigureSpan(Span $span): void
    {
        if ($this->configureSpan !== null) {
            ($this->configureSpan)($span);
        }
    }

    /**
     * Extract upstream trace (same idea as {@see continueTrace()}).
     */
    /**
     * @param  bool  $preserveFinishedSpanBatch  When true, finished spans already recorded (e.g. a {@code queue.publish}
     *                                           child on the HTTP worker) are kept so a sync or same-process queue job
     *                                           exports one trace with both producer and consumer spans.
     */
    public function continueTrace(?string $sentryTraceHeader, ?string $baggageHeader, bool $preserveFinishedSpanBatch = false): self
    {
        $this->spanStack = [];
        if (! $preserveFinishedSpanBatch) {
            $this->finishedRecords = [];
            $this->recordedSpanCount = 0;
        }
        $this->rootTransactionName = null;
        $this->autoManagedHttpSpanId = null;
        $this->autoManagedConsoleSpanId = null;
        $this->autoManagedQueueSpanId = null;
        $this->traceTailExportForced = false;
        $this->upstreamSentrySampledTrue = false;

        $parsed = SentryTraceHeader::parse($sentryTraceHeader);
        $incomingBaggage = Baggage::parse($baggageHeader);

        if ($parsed !== null) {
            $this->traceId = $parsed['trace_id'];
            $this->remoteParentSpanId = $parsed['span_id'];
            $sampled = $parsed['sampled'];
            if ($sampled === false) {
                $this->defaultSampled = false;
                $this->spanRecordingEnabled = false;
            } elseif ($sampled === true) {
                $this->defaultSampled = true;
                $this->spanRecordingEnabled = true;
                $this->upstreamSentrySampledTrue = true;
            } else {
                $this->applyRootSamplerForNewTraceDecision();
            }
            $this->defaultOutgoingSpanId = $parsed['span_id'];
        } else {
            $this->traceId = Id::traceId();
            $this->remoteParentSpanId = null;
            $this->applyRootSamplerForNewTraceDecision();
            $this->defaultOutgoingSpanId = Id::spanId();
        }

        $this->baggageEntries = $incomingBaggage;

        return $this;
    }

    /**
     * Stash the open span stack (e.g. {@code http.server}) before {@see continueTrace()} for a queue worker
     * so sync drivers do not orphan the parent transaction. No-op when the stack is already empty (async worker).
     */
    public function suspendBeforeQueueJobDispatch(): void
    {
        if ($this->getCurrentSpan() === null) {
            return;
        }
        $this->queueWorkerSuspendStack[] = [
            'span_stack' => $this->spanStack,
            'trace' => $this->snapshotTraceContextForQueueSuspend(),
        ];
        $this->spanStack = [];
    }

    /**
     * Pops one level of suspend stack after the job attempt (mirrors {@see suspendBeforeQueueJobDispatch()}).
     */
    public function restoreAfterQueueJobAttempt(): void
    {
        if ($this->queueWorkerSuspendStack === []) {
            return;
        }
        $frame = array_pop($this->queueWorkerSuspendStack);
        $this->applyTraceContextSnapshot($frame['trace']);
        $this->spanStack = $frame['span_stack'];
    }

    /**
     * @return array{
     *     trace_id: string,
     *     remote_parent_span_id: ?string,
     *     baggage_entries: array<string, string>,
     *     default_sampled: ?bool,
     *     span_recording_enabled: bool,
     *     default_outgoing_span_id: ?string,
     *     root_transaction_name: ?string,
     *     auto_managed_http_span_id: ?string,
     *     auto_managed_console_span_id: ?string,
     *     auto_managed_queue_span_id: ?string,
     *     trace_tail_export_forced: bool,
     *     upstream_sentry_sampled_true: bool,
     * }
     */
    private function snapshotTraceContextForQueueSuspend(): array
    {
        return [
            'trace_id' => $this->traceId,
            'remote_parent_span_id' => $this->remoteParentSpanId,
            'baggage_entries' => $this->baggageEntries,
            'default_sampled' => $this->defaultSampled,
            'span_recording_enabled' => $this->spanRecordingEnabled,
            'default_outgoing_span_id' => $this->defaultOutgoingSpanId,
            'root_transaction_name' => $this->rootTransactionName,
            'auto_managed_http_span_id' => $this->autoManagedHttpSpanId,
            'auto_managed_console_span_id' => $this->autoManagedConsoleSpanId,
            'auto_managed_queue_span_id' => $this->autoManagedQueueSpanId,
            'trace_tail_export_forced' => $this->traceTailExportForced,
            'upstream_sentry_sampled_true' => $this->upstreamSentrySampledTrue,
        ];
    }

    /**
     * @param  array{
     *     trace_id: string,
     *     remote_parent_span_id: ?string,
     *     baggage_entries: array<string, string>,
     *     default_sampled: ?bool,
     *     span_recording_enabled: bool,
     *     default_outgoing_span_id: ?string,
     *     root_transaction_name: ?string,
     *     auto_managed_http_span_id: ?string,
     *     auto_managed_console_span_id: ?string,
     *     auto_managed_queue_span_id: ?string,
     *     trace_tail_export_forced: bool,
     *     upstream_sentry_sampled_true: bool,
     * }  $t
     */
    private function applyTraceContextSnapshot(array $t): void
    {
        $this->traceId = $t['trace_id'];
        $this->remoteParentSpanId = $t['remote_parent_span_id'];
        $this->baggageEntries = $t['baggage_entries'];
        $this->defaultSampled = $t['default_sampled'];
        $this->spanRecordingEnabled = $t['span_recording_enabled'];
        $this->defaultOutgoingSpanId = $t['default_outgoing_span_id'];
        $this->rootTransactionName = $t['root_transaction_name'];
        $this->autoManagedHttpSpanId = $t['auto_managed_http_span_id'];
        $this->autoManagedConsoleSpanId = $t['auto_managed_console_span_id'];
        $this->autoManagedQueueSpanId = $t['auto_managed_queue_span_id'];
        $this->traceTailExportForced = $t['trace_tail_export_forced'];
        $this->upstreamSentrySampledTrue = $t['upstream_sentry_sampled_true'];
    }

    private function applyRootSamplerForNewTraceDecision(): void
    {
        $tail = $this->tailSamplingConfig();
        if ($tail !== null && filter_var($tail['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->spanRecordingEnabled = true;
            if ($this->performanceEnabled && $this->sampler !== null) {
                $head = $this->sampler->shouldSample([
                    'trace_id' => $this->traceId,
                    'kind' => 'root',
                ]);
                $this->defaultSampled = $head;
            } else {
                $this->defaultSampled = true;
            }

            return;
        }

        if ($this->performanceEnabled && $this->sampler !== null) {
            $decision = $this->sampler->shouldSample([
                'trace_id' => $this->traceId,
                'kind' => 'root',
            ]);
            $this->spanRecordingEnabled = $decision;
            $this->defaultSampled = $decision;

            return;
        }

        $this->spanRecordingEnabled = true;
        $this->defaultSampled = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tailSamplingConfig(): ?array
    {
        $ts = $this->config['tail_sampling'] ?? null;

        return is_array($ts) ? $ts : null;
    }

    /**
     * When tail sampling is enabled, drop the finished batch unless it looks “interesting” or was forced.
     */
    private function applyTailSamplingDropIfApplicable(): void
    {
        $ts = $this->tailSamplingConfig();
        if ($ts === null || ! filter_var($ts['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        if ($this->traceTailExportForced) {
            return;
        }
        if ($this->upstreamSentrySampledTrue) {
            return;
        }
        if ($this->remoteParentSpanId !== null && filter_var($ts['keep_distributed_participation'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        if ($this->finishedRecords === []) {
            return;
        }

        if ($this->tailPolicyKeepsTrace($ts)) {
            return;
        }

        $this->clearFinishedSpans();
    }

    /**
     * @param  array<string, mixed>  $ts
     */
    private function tailPolicyKeepsTrace(array $ts): bool
    {
        $root = $this->primaryRootFinishedRecord();
        if ($root === null) {
            return false;
        }

        $durationMs = $this->finishedRecordDurationMs($root);
        $slowMs = (int) ($ts['slow_transaction_ms'] ?? 2000);
        if (filter_var($ts['always_export_slow'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && $durationMs !== null && $durationMs >= max(0, $slowMs)) {
            return true;
        }

        $errFrom = (int) ($ts['http_error_status_from'] ?? 500);
        if ($errFrom > 0 && $errFrom <= 599) {
            $code = $this->httpStatusFromRecord($root);
            if ($code !== null && $code >= $errFrom) {
                return true;
            }
        }

        if (filter_var($ts['export_on_span_internal_error'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && $this->finishedRecordsContainInternalError()) {
            return true;
        }

        $residual = (float) ($ts['residual_rate'] ?? 0.0);
        if ($residual > 0.0 && $residual < 1.0 && (mt_rand() / mt_getrandmax()) < $residual) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function finishedRecordDurationMs(array $row): ?int
    {
        $start = $row['start_timestamp'] ?? null;
        $end = $row['end_timestamp'] ?? null;
        if (! is_numeric($start) || ! is_numeric($end)) {
            return null;
        }
        $ms = (int) round(((float) $end - (float) $start) * 1000);

        return $ms >= 0 ? $ms : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function httpStatusFromRecord(array $row): ?int
    {
        $data = $row['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }
        $c = $data['http.status_code'] ?? null;

        return is_numeric($c) ? (int) $c : null;
    }

    private function finishedRecordsContainInternalError(): bool
    {
        foreach ($this->finishedRecords as $row) {
            if (($row['status'] ?? null) === 'internal_error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function primaryRootFinishedRecord(): ?array
    {
        foreach ($this->finishedRecords as $row) {
            if (($row['parent_span_id'] ?? null) === null && ($row['op'] ?? '') === SpanOperation::HTTP_SERVER) {
                return $row;
            }
        }
        foreach ($this->finishedRecords as $row) {
            if (($row['parent_span_id'] ?? null) === null && ($row['op'] ?? '') === SpanOperation::QUEUE_PROCESS) {
                return $row;
            }
        }
        foreach ($this->finishedRecords as $row) {
            if (($row['parent_span_id'] ?? null) === null && ($row['op'] ?? '') === SpanOperation::CONSOLE_COMMAND) {
                return $row;
            }
        }
        foreach ($this->finishedRecords as $row) {
            if (($row['parent_span_id'] ?? null) === null) {
                return $row;
            }
        }

        return $this->finishedRecords[0] ?? null;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public function mergeBaggage(array $entries): self
    {
        $this->baggageEntries = Baggage::merge($this->baggageEntries, $entries);

        return $this;
    }

    /**
     * Start the HTTP server transaction when none is active (used by performance middleware).
     */
    public function startAutoHttpServerTransaction(string $name): ?Span
    {
        $this->ensureInitialized();
        if ($this->getCurrentSpan() !== null) {
            return null;
        }
        $span = $this->startTransaction($name, SpanOperation::HTTP_SERVER);
        $this->autoManagedHttpSpanId = $span->spanId;

        return $span;
    }

    public function finishAutoHttpServerTransaction(?int $httpStatusCode = null): void
    {
        if ($this->autoManagedHttpSpanId === null) {
            return;
        }
        $current = $this->getCurrentSpan();
        if ($current !== null && $current->spanId === $this->autoManagedHttpSpanId && ! $current->isFinished()) {
            if ($httpStatusCode !== null) {
                $current->setData(['http.status_code' => $httpStatusCode]);
            }
            if ($httpStatusCode !== null && $httpStatusCode >= 500) {
                $current->setStatus('internal_error');
            }
            $current->finish();
        }
        $this->autoManagedHttpSpanId = null;
    }

    public function startAutoConsoleTransaction(string $name): ?Span
    {
        $this->ensureInitialized();
        if ($this->getCurrentSpan() !== null) {
            return null;
        }
        $span = $this->startTransaction($name, SpanOperation::CONSOLE_COMMAND);
        $this->autoManagedConsoleSpanId = $span->spanId;

        return $span;
    }

    public function finishAutoConsoleTransaction(): void
    {
        if ($this->autoManagedConsoleSpanId === null) {
            return;
        }
        $current = $this->getCurrentSpan();
        if ($current !== null && $current->spanId === $this->autoManagedConsoleSpanId && ! $current->isFinished()) {
            $current->finish();
        }
        $this->autoManagedConsoleSpanId = null;
    }

    public function startAutoQueueTransaction(string $name): ?Span
    {
        $this->ensureInitialized();
        if ($this->getCurrentSpan() !== null) {
            return null;
        }
        $span = $this->startTransaction($name, SpanOperation::QUEUE_PROCESS);
        $this->autoManagedQueueSpanId = $span->spanId;

        return $span;
    }

    public function finishAutoQueueTransaction(): void
    {
        if ($this->autoManagedQueueSpanId === null) {
            return;
        }
        $current = $this->getCurrentSpan();
        if ($current !== null && $current->spanId === $this->autoManagedQueueSpanId && ! $current->isFinished()) {
            $current->finish();
        }
        $this->autoManagedQueueSpanId = null;
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
     * Optional error-ingest fields to split fingerprints for slow / DB-heavy requests ({@see grouping_slow_path} on the API).
     *
     * @return array<string, mixed>
     */
    public function errorIngestPerformanceGroupingHints(): array
    {
        if (! $this->performanceEnabled || $this->finishedRecords === []) {
            return [];
        }

        $enabled = false;
        $slowRootMs = 2000;
        $slowDbTotalMs = 200;
        if (function_exists('config')) {
            try {
                $cfg = config('lookout-tracing.reporting.performance_grouping');
                $cfg = is_array($cfg) ? $cfg : [];
                $enabled = (bool) ($cfg['enabled'] ?? false);
                $slowRootMs = (int) ($cfg['slow_root_transaction_ms'] ?? 2000);
                $slowDbTotalMs = (int) ($cfg['slow_db_total_ms'] ?? 200);
            } catch (\Throwable) {
                return [];
            }
        }
        if (! $enabled) {
            return [];
        }

        $rootMs = null;
        $dbTotalMs = 0;
        foreach ($this->finishedRecords as $row) {
            $op = (string) ($row['op'] ?? '');
            $parent = $row['parent_span_id'] ?? null;
            $isRoot = $parent === null || $parent === '';
            if ($isRoot && $op === SpanOperation::HTTP_SERVER) {
                $d = $this->spanDurationMsFromFinishedRow($row);
                if ($d !== null) {
                    $rootMs = $d;
                }
            }
            if ($op === SpanOperation::DB_QUERY) {
                $d = $this->spanDurationMsFromFinishedRow($row);
                if ($d !== null) {
                    $dbTotalMs += $d;
                }
            }
        }
        if ($rootMs === null) {
            foreach ($this->finishedRecords as $row) {
                $parent = $row['parent_span_id'] ?? null;
                if ($parent !== null && $parent !== '') {
                    continue;
                }
                $d = $this->spanDurationMsFromFinishedRow($row);
                if ($d !== null) {
                    $rootMs = $rootMs === null ? $d : max($rootMs, $d);
                }
            }
        }

        $slowPath = ($rootMs !== null && $rootMs >= $slowRootMs)
            || ($dbTotalMs >= $slowDbTotalMs);
        if (! $slowPath) {
            return [];
        }

        $out = ['grouping_slow_path' => true];
        if ($dbTotalMs > 0) {
            $out['grouping_db_time_ms'] = $dbTotalMs;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function spanDurationMsFromFinishedRow(array $row): ?int
    {
        $data = $row['data'] ?? [];
        if (is_array($data) && isset($data['db.duration_ms']) && is_numeric($data['db.duration_ms'])) {
            return max(0, (int) round((float) $data['db.duration_ms']));
        }
        $start = $row['start_timestamp'] ?? null;
        $end = $row['end_timestamp'] ?? null;
        if (is_numeric($start) && is_numeric($end)) {
            return max(0, (int) round(((float) $end - (float) $start) * 1000));
        }

        return null;
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

        $body = [
            'trace_id' => $this->traceId,
            'transaction' => $this->rootTransactionName,
            'environment' => $this->config['environment'] ?? null,
            'release' => $this->config['release'] ?? null,
            'spans' => $spans,
        ];
        $sha = $this->config['commit_sha'] ?? null;
        if (is_string($sha)) {
            $sha = strtolower(trim($sha));
            if ($sha !== '') {
                $body['commit_sha'] = substr($sha, 0, 64);
            }
        }
        $dep = $this->config['deployed_at'] ?? null;
        if (is_numeric($dep)) {
            $u = (float) $dep;
            if ($u > 9999999999) {
                $u /= 1000.0;
            }
            if ($u > 0) {
                $body['deployed_at'] = $u;
            }
        }

        return $body;
    }

    /**
     * POST recorded spans to Lookout. Returns false if nothing to send or not configured.
     */
    public function flush(): bool
    {
        return $this->flushWithResult()['ok'];
    }

    /**
     * POST recorded spans and return the HTTP status (and JSON body when present).
     *
     * Use this when you need to detect **403** (trace ingest disabled for the project in Lookout),
     * **402** (billing), or other non-2xx responses — {@see flush()} only returns a boolean.
     *
     * @return array{
     *     ok: bool,
     *     skipped: bool,
     *     status: int|null,
     *     response: array<string, mixed>|null,
     * }
     */
    /**
     * When tail sampling is enabled, drop finished spans that are not “interesting” unless
     * {@see markTraceMustExport()} was used or upstream/distributed rules apply. Resets the
     * forced-export flag. {@see flushWithResult()} invokes this before building the body; call
     * this if you send traces with a custom client instead of {@see flushWithResult()}.
     */
    public function finalizeTailSamplingForExport(): void
    {
        $this->applyTailSamplingDropIfApplicable();
        $this->traceTailExportForced = false;
    }

    public function flushWithResult(): array
    {
        $this->finalizeTailSamplingForExport();

        $body = $this->buildTraceIngestBody();
        if ($body === [] || empty($this->config['api_key'])) {
            return ['ok' => false, 'skipped' => true, 'status' => null, 'response' => null];
        }

        $base = rtrim((string) ($this->config['base_uri'] ?? ''), '/');
        $path = $this->config['ingest_trace_path'] ?? '/api/ingest/trace';
        $path = '/'.ltrim((string) $path, '/');
        $url = $base.$path;

        $r = HttpTransport::postJsonWithResponseRetries(
            $url,
            (string) $this->config['api_key'],
            $body,
            $this->traceIngestMaxAttempts,
            $this->traceIngestRetryDelayMs,
            $this->traceIngestRetryStatuses,
        );

        return [
            'ok' => $r['ok'],
            'skipped' => false,
            'status' => $r['status'],
            'response' => $r['data'],
        ];
    }

    public function clearFinishedSpans(): void
    {
        $this->finishedRecords = [];
        $this->recordedSpanCount = 0;
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
        $this->defaultOutgoingSpanId = $span->spanId;
    }

    /** @internal */
    public function recordSpan(Span $span): void
    {
        if (! $this->spanRecordingEnabled) {
            return;
        }
        if ($this->recordedSpanCount >= $this->traceLimiter->maxSpans()) {
            return;
        }

        $end = $span->endUnix();
        if ($end === null) {
            return;
        }

        $eventsOut = [];
        foreach ($span->spanEvents() as $event) {
            $attrs = $this->traceLimiter->trimEventAttributes($event['attributes']);
            $row = [
                'name' => $event['name'],
                'timestamp' => $event['timestamp'],
                'attributes' => $attrs,
            ];
            if ($this->configureSpanEvent !== null) {
                $mapped = ($this->configureSpanEvent)($row);
                if ($mapped === null) {
                    continue;
                }
                if (is_array($mapped)) {
                    $row = $mapped;
                }
            }
            $eventsOut[] = $row;
        }

        $maxAttrs = $this->traceLimiter->maxAttributesPerSpan();
        $reserveForSpanEvents = ($eventsOut !== [] && $maxAttrs > 0) ? 1 : 0;
        $attrBudget = max(0, $maxAttrs - $reserveForSpanEvents);
        $data = $this->traceLimiter->trimTopLevelKeys($span->data(), $attrBudget);

        if ($eventsOut !== []) {
            $data['span_events'] = $eventsOut;
        }

        $record = [
            'span_id' => $span->spanId,
            'parent_span_id' => $span->parentSpanId,
            'op' => $span->op,
            'description' => $span->description,
            'start_timestamp' => $span->startUnix(),
            'end_timestamp' => $end,
            'status' => $span->status(),
        ];
        if ($data !== []) {
            $record['data'] = $data;
        }

        $this->finishedRecords[] = $record;
        $this->recordedSpanCount++;
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

    /**
     * @internal  Resolve default sampler from config array (used by Laravel service provider).
     *
     * @param  array{class?: class-string<Sampler>|string, config?: array<string, mixed>}  $spec
     */
    public static function makeSamplerFromSpec(array $spec): Sampler
    {
        $class = $spec['class'] ?? RateSampler::class;
        $cfg = is_array($spec['config'] ?? null) ? $spec['config'] : [];

        if (! is_string($class) || ! class_exists($class)) {
            return new RateSampler($cfg);
        }

        if ($class === CompositeOrSampler::class) {
            return new CompositeOrSampler($cfg);
        }

        $instance = new $class($cfg);
        if (! $instance instanceof Sampler) {
            return new RateSampler($cfg);
        }

        return $instance;
    }
}
