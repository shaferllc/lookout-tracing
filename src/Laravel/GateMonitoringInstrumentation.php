<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Contracts\Events\Dispatcher;
use Lookout\Tracing\Gate\Client as GateClient;
use Lookout\Tracing\Tracer;

/**
 * Reports authorization gate/policy evaluations to {@code POST /api/ingest/gate}.
 */
final class GateMonitoringInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        $events->listen(GateEvaluated::class, static function (GateEvaluated $event): void {
            $ability = is_string($event->ability) ? trim($event->ability) : '';
            if ($ability === '' || ! self::shouldCaptureAbility($ability)) {
                return;
            }

            $result = self::normalizeResult($event->result);
            if ($result === null) {
                return;
            }

            if (! self::passesSampling()) {
                return;
            }

            GateClient::instance()->capture(
                $ability,
                $result,
                self::resolveTarget($event->arguments),
                self::resolveUserKey($event->user),
                null,
                null,
                self::currentTraceId(),
            );
        });
    }

    private static function normalizeResult(mixed $result): ?string
    {
        if (is_bool($result)) {
            return $result ? 'allowed' : 'denied';
        }
        if (is_object($result) && method_exists($result, 'allowed')) {
            return $result->allowed() ? 'allowed' : 'denied';
        }
        if ($result === null) {
            return null;
        }

        return $result ? 'allowed' : 'denied';
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private static function resolveTarget(array $arguments): ?string
    {
        foreach ($arguments as $argument) {
            if (is_object($argument)) {
                return substr($argument::class, 0, 512);
            }
        }
        foreach ($arguments as $argument) {
            if (is_string($argument) && trim($argument) !== '') {
                return substr(trim($argument), 0, 512);
            }
        }

        return null;
    }

    private static function resolveUserKey(mixed $user): ?string
    {
        if ($user === null) {
            return null;
        }
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
            if (is_int($id) || (is_string($id) && $id !== '')) {
                return substr((string) $id, 0, 64);
            }
        }

        return null;
    }

    /**
     * Random per-evaluation client-side sampling. Rate 1.0 keeps everything, 0.0 keeps nothing.
     */
    private static function passesSampling(): bool
    {
        $cfg = config('lookout-tracing.gate_monitoring');
        $rate = is_array($cfg) ? (float) ($cfg['sample_rate'] ?? 1.0) : 1.0;
        $rate = max(0.0, min(1.0, $rate));

        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    private static function shouldCaptureAbility(string $ability): bool
    {
        $cfg = config('lookout-tracing.gate_monitoring');
        if (! is_array($cfg)) {
            return false;
        }

        $ignore = $cfg['ignore_abilities'] ?? [];
        if (is_array($ignore) && in_array($ability, $ignore, true)) {
            return false;
        }

        $allowlist = $cfg['allowlist'] ?? [];
        if (is_array($allowlist) && $allowlist !== []) {
            return in_array($ability, $allowlist, true);
        }

        return true;
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $gateCfg = is_array($cfg['gate_monitoring'] ?? null) ? $cfg['gate_monitoring'] : [];
        if (empty($gateCfg['enabled'])) {
            return false;
        }

        $key = $cfg['api_key'] ?? null;
        $base = $cfg['base_uri'] ?? null;

        return is_string($key) && $key !== '' && is_string($base) && rtrim(trim($base), '/') !== '';
    }

    private static function currentTraceId(): ?string
    {
        $id = Tracer::instance()->traceId();

        return $id !== '' ? $id : null;
    }
}
