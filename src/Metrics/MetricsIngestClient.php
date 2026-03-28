<?php

declare(strict_types=1);

namespace Lookout\Tracing\Metrics;

use Lookout\Tracing\HttpTransport;
use Lookout\Tracing\Tracer;

/**
 * Buffered custom metrics to {@code POST /api/ingest/metric}: {@code count}, {@code gauge},
 * {@code distribution}, {@code flush()}.
 *
 * Lookout stores each sample (with optional {@code trace_id} from the active tracer) so you can
 * correlate spikes with traces and issues in the same project.
 */
final class MetricsIngestClient
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     metric_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null,
     *     max_buffer?: int,
     *     before_send_metric?: callable(array<string, mixed>): (array<string, mixed>|null)|null,
     * }  $config
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function resetForTesting(): void
    {
        self::$config = [];
        self::$instance = null;
    }

    public function isEnabled(): bool
    {
        return (bool) (self::$config['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function count(string $name, float $delta = 1.0, array $attributes = [], ?string $unit = null): void
    {
        $this->enqueue('counter', $name, $delta, $attributes, $unit);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function gauge(string $name, float $value, array $attributes = [], ?string $unit = null): void
    {
        $this->enqueue('gauge', $name, $value, $attributes, $unit);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function distribution(string $name, float $value, array $attributes = [], ?string $unit = null): void
    {
        $this->enqueue('distribution', $name, $value, $attributes, $unit);
    }

    /**
     * @param  array<string, mixed>  $row  Normalized ingest row ({@code name}, {@code kind}, {@code value} required).
     */
    public function enqueueEntry(array $row): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! isset($row['name'], $row['kind'], $row['value']) || ! is_string($row['name']) || ! is_string($row['kind']) || ! is_numeric($row['value'])) {
            return;
        }
        $v = (float) $row['value'];
        if (! is_finite($v)) {
            return;
        }
        $filtered = $this->applyBeforeSend($this->applyDefaults($row));
        if ($filtered === null) {
            return;
        }
        $this->buffer[] = $filtered;
        $this->maybeAutoFlush();
    }

    /**
     * POST buffered metric rows (chunks of up to 100). Clears the buffer after the attempt.
     */
    public function flush(): bool
    {
        if ($this->buffer === []) {
            return true;
        }
        $key = (string) (self::$config['api_key'] ?? '');
        $url = $this->ingestUrl();
        if ($key === '' || $url === '') {
            $this->buffer = [];

            return false;
        }
        $maxChunk = 100;
        $ok = true;
        $chunks = array_chunk($this->buffer, $maxChunk);
        $this->buffer = [];
        foreach ($chunks as $chunk) {
            $res = HttpTransport::postJsonWithResponse($url, $key, ['entries' => $chunk]);
            $status = $res['status'] ?? 0;
            if ($status !== 202) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enqueue(string $kind, string $name, float $value, array $attributes, ?string $unit): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! is_finite($value)) {
            return;
        }
        $row = [
            'name' => $name,
            'kind' => $kind,
            'value' => $value,
        ];
        if ($unit !== null && $unit !== '') {
            $row['unit'] = $unit;
        }
        if ($attributes !== []) {
            $row['attributes'] = $attributes;
        }
        $filtered = $this->applyBeforeSend($this->applyDefaults($row));
        if ($filtered === null) {
            return;
        }
        $this->buffer[] = $filtered;
        $this->maybeAutoFlush();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyDefaults(array $row): array
    {
        $env = self::$config['environment'] ?? null;
        if (is_string($env) && $env !== '' && ! isset($row['environment'])) {
            $row['environment'] = $env;
        }
        $rel = self::$config['release'] ?? null;
        if (is_string($rel) && $rel !== '' && ! isset($row['release'])) {
            $row['release'] = $rel;
        }
        if (! isset($row['trace_id'])) {
            $tid = Tracer::instance()->traceId();
            if ($tid !== '') {
                $row['trace_id'] = $tid;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function applyBeforeSend(array $row): ?array
    {
        $cb = self::$config['before_send_metric'] ?? null;
        if (! is_callable($cb)) {
            return $row;
        }
        $out = $cb($row);

        return is_array($out) ? $out : null;
    }

    private function maybeAutoFlush(): void
    {
        $max = (int) (self::$config['max_buffer'] ?? 500);
        $max = max(50, min(1000, $max));
        if (count($this->buffer) >= $max) {
            $this->flush();
        }
    }

    private function ingestUrl(): string
    {
        $base = isset(self::$config['base_uri']) && is_string(self::$config['base_uri'])
            ? rtrim(self::$config['base_uri'], '/') : '';
        $path = (string) (self::$config['metric_ingest_path'] ?? '/api/ingest/metric');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
