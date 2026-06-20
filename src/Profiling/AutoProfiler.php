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

    private static int $periodUs = 10_000;

    /** @var 'wall'|'cpu' */
    private static string $eventType = 'wall';

    private static int $minDurationMs = 0;

    private static int $maxSamples = 10_000;

    private static ?string $environment = null;

    private static ?string $release = null;

    /** Active \ExcimerProfiler while a capture is running, else null. */
    private static ?object $profiler = null;

    private static float $startedAt = 0.0;

    /** Test seam: when set, used instead of a real random draw. */
    private static ?float $forcedFraction = null;

    /**
     * @param array{
     *     enabled?: bool,
     *     sample_rate?: float|int|string,
     *     period_us?: int|string,
     *     event_type?: string,
     *     min_duration_ms?: int|string,
     *     max_samples?: int|string,
     *     environment?: string|null,
     *     release?: string|null
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
        return self::$profiler !== null;
    }

    /**
     * Begin a capture when enabled, Excimer is available, none is running, and this transaction
     * wins the sample draw. Safe to call unconditionally — it self-gates and never throws.
     */
    public static function maybeStart(): void
    {
        try {
            if (! self::$enabled || self::$profiler !== null) {
                return;
            }
            if (self::$sampleRate <= 0.0 || ! self::shouldSample()) {
                return;
            }
            if (! ExcimerExporter::isAvailable()) {
                return;
            }

            // Excimer-only branch: constants/classes here are never reached without the extension.
            $profiler = new \ExcimerProfiler;
            $profiler->setPeriod(self::$periodUs / 1_000_000);
            $profiler->setEventType(self::$eventType === 'cpu' ? EXCIMER_CPU : EXCIMER_REAL);
            $profiler->start();

            self::$profiler = $profiler;
            self::$startedAt = microtime(true);
        } catch (\Throwable) {
            self::$profiler = null;
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
        $profiler = self::$profiler;
        if ($profiler === null) {
            return false;
        }
        self::$profiler = null;

        try {
            if (method_exists($profiler, 'stop')) {
                $profiler->stop();
            }

            $elapsedMs = (int) round((microtime(true) - self::$startedAt) * 1000);
            if (self::$minDurationMs > 0 && $elapsedMs < self::$minDurationMs) {
                return false;
            }

            $log = method_exists($profiler, 'getLog')
                ? $profiler->getLog()
                : (method_exists($profiler, 'flush') ? $profiler->flush() : null);
            if (! is_object($log)) {
                return false;
            }

            return self::buildAndSend($log, $context + self::tracerContext());
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
        self::$periodUs = 10_000;
        self::$eventType = 'wall';
        self::$minDurationMs = 0;
        self::$maxSamples = 10_000;
        self::$environment = null;
        self::$release = null;
        self::$profiler = null;
        self::$startedAt = 0.0;
        self::$forcedFraction = null;
    }

    /** Test seam: force the next sample draw (0.0 = always sample, 1.0 = never). */
    public static function forceSampleFractionForTesting(?float $fraction): void
    {
        self::$forcedFraction = $fraction;
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
