<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Lookout\Tracing\DomainEvent\Client as DomainEventClient;
use Lookout\Tracing\Tracer;

/**
 * Reports dispatched application events to {@code POST /api/ingest/event}.
 */
final class DomainEventMonitoringInstrumentation
{
    private static int $wildcardSeq = 0;

    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        $cfg = config('lookout-tracing.event_monitoring');
        if (! is_array($cfg)) {
            return;
        }

        $allowlist = $cfg['allowlist'] ?? [];
        if (is_array($allowlist)) {
            foreach ($allowlist as $class) {
                if (! is_string($class) || $class === '' || ! class_exists($class)) {
                    continue;
                }
                $events->listen($class, function (object $event) use ($class, $events): void {
                    self::record($class, $events, $event);
                });
            }
        }

        if (empty($cfg['wildcard'])) {
            return;
        }

        $ignore = $cfg['ignore_prefixes'] ?? ['Illuminate\\', 'Laravel\\', 'Livewire\\'];
        $ignore = is_array($ignore) ? $ignore : [];
        $sampleEvery = max(1, (int) ($cfg['wildcard_sample_every'] ?? 1));

        $events->listen('*', function (mixed $eventName, array $payload) use ($events, $ignore, $sampleEvery): void {
            if (! is_string($eventName) || $eventName === '' || $eventName === '*') {
                return;
            }
            foreach ($ignore as $prefix) {
                if (is_string($prefix) && $prefix !== '' && str_starts_with($eventName, $prefix)) {
                    return;
                }
            }
            self::$wildcardSeq++;
            if ((self::$wildcardSeq % $sampleEvery) !== 0) {
                return;
            }
            self::record($eventName, $events, $payload[0] ?? null);
        });
    }

    private static function record(string $eventName, Dispatcher $events, mixed $event): void
    {
        if (! self::enabled()) {
            return;
        }

        $listeners = 0;
        if (method_exists($events, 'getListeners')) {
            $listeners = count($events->getListeners($eventName));
        }

        $broadcast = false;
        if (is_object($event) && method_exists($event, 'broadcastWhen')) {
            try {
                $broadcast = (bool) $event->broadcastWhen();
            } catch (\Throwable) {
                $broadcast = false;
            }
        }

        DomainEventClient::instance()->capture(
            $eventName,
            $listeners > 0 ? $listeners : null,
            $broadcast,
            null,
            null,
            self::currentTraceId(),
        );
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $eventCfg = is_array($cfg['event_monitoring'] ?? null) ? $cfg['event_monitoring'] : [];
        if (empty($eventCfg['enabled'])) {
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
