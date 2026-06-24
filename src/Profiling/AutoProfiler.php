<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

use Lookout\Tracing\Tracer;

/**
 * Automatic, zero-code CPU/wall profiling.
 *
 * When enabled and the Excimer extension is present, a sampled fraction of transactions
 * (web requests, console commands, queue jobs) are profiled and uploaded via
 * {@see ProfileClient::sendProfile()} — the developer writes no profiling code.
 *
 * Hard guarantees:
 * - **No-op without Excimer.** {@see ExcimerExporter::isAvailable()} gates all extension use, and
 *   {@code \ExcimerProfiler} / {@code EXCIMER_*} are only referenced inside that guarded branch.
 * - **Never throws into the host app.** Every public entry point swallows exceptions and degrades to a no-op.
 */
final class AutoProfiler
{
    private static bool $enabled = false;

    private static float $sampleRate = 0.0;

    /**
     * When true, profiling follows the trace sampling decision: every transaction whose trace is
     * being recorded is profiled (so each sampled trace carries a linkable CPU profile) and the
     * independent {@see $sampleRate} draw is ignored. When false, the legacy independent draw applies.
     */
    private static bool $followTraceSampling = true;

    private static int $periodUs = 10_000;

    /** @var 'wall'|'cpu' */
    private static string $eventType = 'wall';

    private static int $minDurationMs = 0;

    private static int $maxSamples = 10_000;

    private static ?string $environment = null;

    private static ?string $release = null;

    /** Active capture while profiling, else null. */
    private static ?ProfileCapture $capture = null;

    private static float $startedAt = 0.0;

    /** Test seam: when set, used instead of a real random draw. */
    private static ?float $forcedFraction = null;

    /**
     * @param array{
     *     enabled?: bool,
     *     sample_rate?: float|int|string,
     *     follow_trace_sampling?: bool,
     *     period_us?: int|string,
     *     event_type?: string,
     *     min_duration_ms?: int|string,
     *     max_samples?: int|string,
     *     environment?: string|null,
     *     release?: string|null,
     * } $cfg
     */
    public static function configure(array $cfg): void
    {
        if (array_key_exists('enabled', $cfg)) {
            self::$enabled = (bool) $cfg['enabled'];
        }
        if (array_key_exists('sample_rate', $cfg)) {
            self::$sampleRate = max(0.0, min(1.0, (float) $cfg['sample_rate']));
        }
        if (array_key_exists('follow_trace_sampling', $cfg)) {
            self::$followTraceSampling = (bool) $cfg['follow_trace_sampling'];
        }
        if (array_key_exists('period_us', $cfg)) {
            self::$periodUs = max(1, (int) $cfg['period_us']);
        }
        if (array_key_exists('event_type', $cfg)) {
            self::$eventType = ((string) $cfg['event_type']) === 'cpu' ? 'cpu' : 'wall';
        }
        if (array_key_exists('min_duration_ms', $cfg)) {
            self::$minDurationMs = max(0, (int) $cfg['min_duration_ms']);
        }
        if (array_key_exists('max_samples', $cfg)) {
            self::$maxSamples = max(0, (int) $cfg['max_samples']);
        }
        if (array_key_exists('environment', $cfg)) {
            self::$environment = self::nonEmptyString($cfg['environment']);
        }
        if (array_key_exists('release', $cfg)) {
            self::$release = self::nonEmptyString($cfg['release']);
        }
    }

    public static function isRunning(): bool
    {
        return self::$capture !== null;
    }

    /**
     * Begin a capture when enabled, Excimer is available, none is running, and this transaction
     * wins the sample draw. Safe to call unconditionally — it self-gates and never throws.
     */
    public static function maybeStart(): void
    {
        try {
            if (self::$capture !== null || ! self::shouldProfileCurrentTransaction()) {
                return;
            }

            $capture = ProfileIngestClient::instance()->beginCapture();
            if ($capture === null) {
                return;
            }

            self::$capture = $capture;
            self::$startedAt = $capture->startedAt;
        } catch (\Throwable) {
            self::$capture = null;
        }
    }

    /**
     * Stop the active capture (if any), gate on min duration, and upload the profile.
     * Context (trace_id, transaction) is pulled from the tracer; caller overrides win.
     *
     * @param  array<string, mixed>  $context
     */
    public static function finishAndSend(array $context = []): bool
    {
        $capture = self::$capture;
        if ($capture === null) {
            return false;
        }
        self::$capture = null;

        try {
            $elapsedMs = (int) round((microtime(true) - self::$startedAt) * 1000);
            if (self::$minDurationMs > 0 && $elapsedMs < self::$minDurationMs) {
                return false;
            }

            $merged = $context + self::tracerContext();

            return ProfileIngestClient::instance()->finishCapture(
                $capture,
                is_string($merged['transaction'] ?? null) && $merged['transaction'] !== ''
                    ? (string) $merged['transaction']
                    : 'transaction',
                [],
                $merged,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the ingest body for a profiler log and upload it. Extension-free and duck-typed
     * ($log only needs getSpeedscopeData()), so it is the seam used by tests.
     *
     * @param  array<string, mixed>  $context
     */
    public static function buildAndSend(object $log, array $context = []): bool
    {
        try {
            return ProfileClient::sendProfile(self::buildPayload($log, $context));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pure: the exact ingest body that would be POSTed (agent/format/data + merged context).
     * Configured environment/release are defaults; caller context wins.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function buildPayload(object $log, array $context = []): array
    {
        $defaults = array_filter([
            'environment' => self::$environment,
            'release' => self::$release,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        $caller = array_filter($context, static fn (mixed $v): bool => $v !== null && $v !== '');

        return ExcimerExporter::toIngestPayload($log, array_merge($defaults, $caller));
    }

    public static function maxSamples(): int
    {
        return self::$maxSamples;
    }

    public static function resetForTesting(): void
    {
        self::$enabled = false;
        self::$sampleRate = 0.0;
        self::$followTraceSampling = true;
        self::$periodUs = 10_000;
        self::$eventType = 'wall';
        self::$minDurationMs = 0;
        self::$maxSamples = 10_000;
        self::$environment = null;
        self::$release = null;
        self::$capture = null;
        self::$startedAt = 0.0;
        self::$forcedFraction = null;
    }

    /** Test seam: force the next sample draw (0.0 = always sample, 1.0 = never). */
    public static function forceSampleFractionForTesting(?float $fraction): void
    {
        self::$forcedFraction = $fraction;
    }

    /**
     * Whether the current transaction should be profiled. When following trace sampling, profile
     * exactly the transactions whose trace is being recorded; otherwise apply the legacy independent
     * sample-rate draw. Public so the gate can be asserted in tests without the Excimer extension.
     */
    public static function shouldProfileCurrentTransaction(): bool
    {
        if (! self::$enabled) {
            return false;
        }

        if (self::$followTraceSampling) {
            try {
                return Tracer::instance()->isSpanRecordingEnabled();
            } catch (\Throwable) {
                return false;
            }
        }

        if (self::$sampleRate <= 0.0) {
            return false;
        }

        return self::shouldSample();
    }

    private static function shouldSample(): bool
    {
        $draw = self::$forcedFraction ?? (mt_rand() / mt_getrandmax());

        return $draw < self::$sampleRate;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tracerContext(): array
    {
        try {
            $f = Tracer::instance()->errorIngestTraceFields();

            return array_filter([
                'trace_id' => $f['trace_id'] ?? null,
                'transaction' => $f['transaction'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
        } catch (\Throwable) {
            return [];
        }
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }
}
