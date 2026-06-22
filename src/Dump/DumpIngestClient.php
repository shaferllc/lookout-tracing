<?php

declare(strict_types=1);

namespace Lookout\Tracing\Dump;

use Lookout\Tracing\HttpTransport;
use Lookout\Tracing\Tracer;

/**
 * Buffered structured dumps to {@code POST /api/ingest/dump}. Captures arbitrary values as normalized
 * trees via {@see DumpSerializer}, batches them (small batches — entries are heavy), and flushes at the
 * end of the request/command/job lifecycle. {@code dd()} flushes synchronously before the process dies.
 */
final class DumpIngestClient
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $capturedThisRequest = 0;

    private bool $suppressedNoticeQueued = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     api_key?: string|null,
     *     base_uri?: string|null,
     *     dump_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null,
     *     sample_rate?: float,
     *     max_batch?: int,
     *     max_per_request?: int,
     *     serializer?: array<string, mixed>,
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
     * Reset the per-request capture counter (call at the start of each request/command/job).
     */
    public function startRequest(): void
    {
        $this->capturedThisRequest = 0;
        $this->suppressedNoticeQueued = false;
    }

    /**
     * Capture a value as a dump. Returns silently when disabled or over the per-request cap.
     */
    public function capture(mixed $value, ?string $label = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (! $this->passesSampling()) {
            return;
        }

        $max = $this->maxPerRequest();
        if ($this->capturedThisRequest >= $max) {
            if (! $this->suppressedNoticeQueued) {
                $this->suppressedNoticeQueued = true;
                $this->buffer[] = $this->applyDefaults([
                    'label' => $label,
                    'preview' => 'Further dumps suppressed (over '.$max.' per request)',
                    'root_type' => 'truncated',
                    'truncated' => true,
                    'tree' => ['type' => 'truncated', 'preview' => '…'],
                ]);
            }

            return;
        }
        $this->capturedThisRequest++;

        $serializer = new DumpSerializer($this->serializerOptions());
        $result = $serializer->serialize($value, $label);

        $this->buffer[] = $this->applyDefaults([
            'label' => $label,
            'preview' => $result['preview'],
            'root_type' => $result['root_type'],
            'root_class' => $result['root_class'],
            'tree' => $result['tree'],
            'truncated' => $result['truncated'],
            'format' => 'json',
        ]);

        if (count($this->buffer) >= $this->maxBatch()) {
            $this->flush();
        }
    }

    /**
     * POST buffered dumps (chunks of up to max_batch). Clears the buffer on completion.
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
        $chunks = array_chunk($this->buffer, $this->maxBatch());
        $this->buffer = [];
        $ok = true;
        foreach ($chunks as $chunk) {
            $res = HttpTransport::postJsonWithResponse($url, $key, ['entries' => $chunk]);
            if (($res['status'] ?? 0) !== 202) {
                $ok = false;
            }
        }

        return $ok;
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
        if (! isset($row['source'])) {
            $row['source'] = 'php';
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
     * Random per-dump client-side sampling. Rate 1.0 keeps everything, 0.0 keeps nothing.
     */
    private function passesSampling(): bool
    {
        $rate = (float) (self::$config['sample_rate'] ?? 1.0);
        $rate = max(0.0, min(1.0, $rate));

        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    private function maxBatch(): int
    {
        return max(1, min(100, (int) (self::$config['max_batch'] ?? 20)));
    }

    private function maxPerRequest(): int
    {
        return max(1, (int) (self::$config['max_per_request'] ?? 100));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializerOptions(): array
    {
        $opts = self::$config['serializer'] ?? null;

        return is_array($opts) ? $opts : [];
    }

    private function ingestUrl(): string
    {
        $base = isset(self::$config['base_uri']) && is_string(self::$config['base_uri'])
            ? rtrim(self::$config['base_uri'], '/') : '';
        $path = (string) (self::$config['dump_ingest_path'] ?? '/api/ingest/dump');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
