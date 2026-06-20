<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Lookout\Tracing\Model\Client as ModelChangeClient;
use Lookout\Tracing\Support\IngestSelfMonitoring;
use Lookout\Tracing\Tracer;

/**
 * Reports Eloquent created/updated/deleted events to {@code POST /api/ingest/model}.
 */
final class ModelMonitoringInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        $events->listen('eloquent.*', static function (mixed $eventName, array $payload): void {
            if (IngestSelfMonitoring::shouldSkipTerminateFlushes()) {
                return;
            }

            if (! is_string($eventName) || ! str_starts_with($eventName, 'eloquent.')) {
                return;
            }

            if (! preg_match('/^eloquent\.(created|updated|deleted):\s*(.+)$/', $eventName, $matches)) {
                return;
            }

            $action = $matches[1];
            $modelClass = trim($matches[2]);
            if ($modelClass === '' || ! self::shouldCaptureModel($modelClass)) {
                return;
            }

            $model = $payload[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }

            $key = $model->getKey();
            $modelKey = $key !== null ? (string) $key : null;

            $changedKeys = null;
            if ($action === 'updated') {
                $changes = array_keys($model->getChanges());
                $ignore = self::ignoredChangeAttributes();
                $changes = array_values(array_filter($changes, static fn (string $attr): bool => ! in_array($attr, $ignore, true)));
                if ($changes !== []) {
                    $changedKeys = array_slice($changes, 0, 32);
                }
            }

            ModelChangeClient::instance()->capture(
                $modelClass,
                $action,
                $modelKey,
                $changedKeys,
                null,
                null,
                self::currentTraceId(),
            );
        });
    }

    private static function shouldCaptureModel(string $modelClass): bool
    {
        $cfg = config('lookout-tracing.model_monitoring');
        if (! is_array($cfg)) {
            return false;
        }

        $ignore = $cfg['ignore_prefixes'] ?? ['Illuminate\\', 'Laravel\\', 'Livewire\\'];
        if (is_array($ignore)) {
            foreach ($ignore as $prefix) {
                if (is_string($prefix) && $prefix !== '' && str_starts_with($modelClass, $prefix)) {
                    return false;
                }
            }
        }

        $allowlist = $cfg['allowlist'] ?? [];
        if (is_array($allowlist) && $allowlist !== []) {
            return in_array($modelClass, $allowlist, true);
        }

        $namespace = $cfg['namespace_prefix'] ?? 'App\\';
        if (is_string($namespace) && $namespace !== '') {
            return str_starts_with($modelClass, $namespace);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function ignoredChangeAttributes(): array
    {
        $cfg = config('lookout-tracing.model_monitoring');
        if (! is_array($cfg)) {
            return ['updated_at'];
        }
        $ignore = $cfg['ignore_change_attributes'] ?? ['updated_at', 'created_at'];
        if (! is_array($ignore)) {
            return ['updated_at'];
        }

        return array_values(array_filter($ignore, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $modelCfg = is_array($cfg['model_monitoring'] ?? null) ? $cfg['model_monitoring'] : [];
        if (empty($modelCfg['enabled'])) {
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
