<?php

declare(strict_types=1);

namespace Lookout\Tracing\Logging;

use Lookout\Tracing\HttpTransport;
use Lookout\Tracing\Tracer;

/**
 * Buffered structured logs to {@code POST /api/ingest/log}, similar in spirit to
 * Sentry’s PHP structured logs API (see https://docs.sentry.io/platforms/php/logs/):
 * {@code logger()->info(...)}, {@code flush()}, optional Monolog handler.
 */
final class LogIngestClient
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
     *     log_ingest_path?: string|null,
     *     environment?: string|null,
     *     release?: string|null,
     *     max_buffer?: int,
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

    public function trace(string $message, ?array $values = null, array $attributes = []): void
    {
        $this->log('trace', $message, $values, $attributes);
    }

    public function debug(string $message, ?array $values = null, array $attributes = []): void
    {
        $this->log('debug', $message, $values, $attributes);
    }

    public function info(string $message, ?array $values = null, array $attributes = []): void
    {
        $this->log('info', $message, $values, $attributes);
    }

    public function warn(string $message, ?array $values = null, array $attributes = []): void
    {
        $this->log('warn', $message, $values, $attributes);
    }

    public function error(string $message, ?array $values = null, array $attributes = []): void
    {
        $this->log('error', $message, $values, $attributes);
    }

    public function fatal(string $message, ?array $values = null, array $attributes = []): void
    {
        $this->log('fatal', $message, $values, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function log(string $level, string $message, ?array $values = null, array $attributes = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if ($values !== null && $values !== []) {
            try {
                $message = vsprintf($message, $values);
            } catch (\Throwable) {
                // keep template if placeholders do not match
            }
        }
        $this->enqueue($level, $message, $attributes);
    }

    /**
     * @param  array<string, mixed>  $row  Single ingest row (message required); used by the Monolog handler.
     */
    public function enqueueEntry(array $row): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        if (! isset($row['message']) || ! is_string($row['message'])) {
            return;
        }
        $this->buffer[] = $this->applyDefaults($row);
        $this->maybeAutoFlush();
    }

    /**
     * POST buffered entries (chunks of up to 200). Clears the buffer on completion (even on partial failure).
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
        $maxChunk = 200;
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
    private function enqueue(string $level, string $message, array $attributes): void
    {
        $row = [
            'level' => $level,
            'message' => $message,
            'source' => 'php',
        ];
        if ($attributes !== []) {
            $row['attributes'] = $attributes;
        }
        $this->buffer[] = $this->applyDefaults($row);
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

    private function maybeAutoFlush(): void
    {
        $max = (int) (self::$config['max_buffer'] ?? 200);
        $max = max(10, min(500, $max));
        if (count($this->buffer) >= $max) {
            $this->flush();
        }
    }

    private function ingestUrl(): string
    {
        $base = isset(self::$config['base_uri']) && is_string(self::$config['base_uri'])
            ? rtrim(self::$config['base_uri'], '/') : '';
        $path = (string) (self::$config['log_ingest_path'] ?? '/api/ingest/log');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
