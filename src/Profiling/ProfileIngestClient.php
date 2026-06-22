<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

use Lookout\Tracing\Tracer;

/**
 * Manual and timed CPU/wall profiles via {@code start()}/{@code stop()}, {@code time()}, and fluent {@code profile()}.
 *
 * Uses Excimer when the extension is installed; otherwise cooperative {@see ManualPulseSampler} stack samples.
 * Automatic request profiling stays on {@see AutoProfiler}; this client is for explicit code blocks.
 */
final class ProfileIngestClient
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     profile_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null,
     *     period_us?: int,
     *     event_type?: string,
     *     manual_pulse_fallback?: bool,
     * }  $config
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
        ProfileClient::configure($config);
    }

    public static function resetForTesting(): void
    {
        self::$config = [];
        self::$instance = null;
        ProfileClient::resetForTesting();
    }

    public function isEnabled(): bool
    {
        return (bool) (self::$config['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $contextOverrides  trace_id, environment, release
     */
    public function start(string $transaction, array $meta = [], array $contextOverrides = []): ProfileTimer
    {
        if (! $this->isEnabled()) {
            return new ProfileTimer($this, $transaction, $meta, null, $contextOverrides);
        }

        return new ProfileTimer($this, $transaction, $meta, $this->beginCapture(), $contextOverrides);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function time(string $transaction, callable $fn, array $meta = []): mixed
    {
        $timer = $this->start($transaction, $meta);
        try {
            return $fn();
        } finally {
            if (! $timer->isFinished()) {
                $timer->stop();
            }
        }
    }

    public function profile(string $transaction): ProfileBuilder
    {
        $builder = new ProfileBuilder($this, $transaction);
        $env = is_string(self::$config['environment'] ?? null) ? trim((string) self::$config['environment']) : '';
        $release = is_string(self::$config['release'] ?? null) ? trim((string) self::$config['release']) : '';
        if ($env !== '') {
            $builder->environment($env);
        }
        if ($release !== '') {
            $builder->release($release);
        }

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function send(array $body): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return ProfileClient::sendProfile($body);
    }

    /**
     * @param  list<array{file: string, line: int, samples: int}>  $frames
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $context
     */
    public function sendAggregate(array $frames, array $meta = [], array $context = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return ProfileClient::sendProfile(
            LookoutProfileV1Payload::aggregateIngestBody($frames, $meta, $context)
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $contextOverrides
     */
    public function finishCapture(ProfileCapture $capture, string $transaction, array $meta = [], array $contextOverrides = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $context = $this->mergedContext($transaction, $meta, $contextOverrides);

            if ($capture->backend === ProfileCapture::BACKEND_EXCIMER) {
                $profiler = $capture->handle;
                if (method_exists($profiler, 'stop')) {
                    $profiler->stop();
                }
                $log = method_exists($profiler, 'getLog')
                    ? $profiler->getLog()
                    : (method_exists($profiler, 'flush') ? $profiler->flush() : null);
                if (! is_object($log)) {
                    return false;
                }
                unset($context['meta']);

                return ProfileClient::sendProfile(AutoProfiler::buildPayload($log, $context));
            }

            if ($capture->backend === ProfileCapture::BACKEND_MANUAL) {
                /** @var ManualPulseSampler $sampler */
                $sampler = $capture->handle;
                $sampler->pulse();
                $payload = $sampler->toIngestPayload($context);
                if ($meta !== [] && is_array($payload['data'] ?? null)) {
                    $payload['data']['meta'] = array_merge(
                        is_array($payload['data']['meta'] ?? null) ? $payload['data']['meta'] : [],
                        $meta
                    );
                }

                return ProfileClient::sendProfile($payload);
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    public function beginCapture(): ?ProfileCapture
    {
        try {
            if (ExcimerExporter::isAvailable()) {
                $periodUs = max(1, (int) (self::$config['period_us'] ?? 10_000));
                $eventType = is_string(self::$config['event_type'] ?? null) && self::$config['event_type'] === 'cpu'
                    ? 'cpu'
                    : 'wall';

                $profiler = new \ExcimerProfiler;
                $profiler->setPeriod($periodUs / 1_000_000);
                $profiler->setEventType($eventType === 'cpu' ? EXCIMER_CPU : EXCIMER_REAL);
                $profiler->start();

                return new ProfileCapture(ProfileCapture::BACKEND_EXCIMER, $profiler, microtime(true));
            }

            if (! (bool) (self::$config['manual_pulse_fallback'] ?? false)) {
                return null;
            }

            $sampler = new ManualPulseSampler;
            $sampler->pulse();

            return new ProfileCapture(ProfileCapture::BACKEND_MANUAL, $sampler, microtime(true));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $contextOverrides
     * @return array<string, mixed>
     */
    private function mergedContext(string $transaction, array $meta = [], array $contextOverrides = []): array
    {
        $env = is_string(self::$config['environment'] ?? null) ? trim((string) self::$config['environment']) : '';
        $release = is_string(self::$config['release'] ?? null) ? trim((string) self::$config['release']) : '';

        try {
            $fields = Tracer::instance()->errorIngestTraceFields();
        } catch (\Throwable) {
            $fields = [];
        }

        $traceId = isset($fields['trace_id']) && is_string($fields['trace_id']) ? $fields['trace_id'] : null;

        $context = array_filter([
            'transaction' => $transaction,
            'trace_id' => $contextOverrides['trace_id'] ?? $traceId,
            'environment' => $contextOverrides['environment'] ?? ($env !== '' ? $env : null),
            'release' => $contextOverrides['release'] ?? ($release !== '' ? $release : null),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        if ($meta !== []) {
            $context['meta'] = $meta;
        }

        return $context;
    }
}
