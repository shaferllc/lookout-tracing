<?php

declare(strict_types=1);

namespace Lookout\Tracing\Gate;

use Lookout\Tracing\HttpTransport;

/**
 * Buffered authorization gate/policy check reporting to {@code POST /api/ingest/gate}.
 */
final class Client
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
     *     gate_ingest_path?: string|null,
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

    /**
     * @return list<array<string, mixed>>
     *
     * @internal
     */
    public function pendingForTesting(): array
    {
        return $this->buffer;
    }

    public function isEnabled(): bool
    {
        return (bool) (self::$config['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function capture(
        string $ability,
        string $result,
        ?string $target = null,
        ?string $userKey = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $traceId = null,
        ?array $meta = null,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $this->buffer[] = array_filter([
            'ability' => $ability,
            'result' => $result,
            'target' => $target,
            'user' => $userKey,
            'environment' => $environment ?? (self::$config['environment'] ?? null),
            'release' => $release ?? (self::$config['release'] ?? null),
            'trace_id' => $traceId,
            'meta' => $meta !== null && $meta !== [] ? $meta : null,
        ], fn (mixed $v): bool => $v !== null);

        $max = (int) (self::$config['max_buffer'] ?? 200);
        if ($max > 0 && count($this->buffer) > $max) {
            $this->buffer = array_slice($this->buffer, -$max);
        }
    }

    public function flush(): bool
    {
        if (! $this->isEnabled() || $this->buffer === []) {
            return true;
        }

        $url = self::gateUrl();
        $key = (string) (self::$config['api_key'] ?? '');
        if ($url === '' || $key === '') {
            $this->buffer = [];

            return false;
        }

        $entries = $this->buffer;
        $this->buffer = [];

        $res = HttpTransport::postJsonWithResponse($url, $key, ['entries' => $entries]);

        return (bool) ($res['ok'] ?? false);
    }

    private static function gateUrl(): string
    {
        $base = rtrim((string) (self::$config['base_uri'] ?? ''), '/');
        $path = (string) (self::$config['gate_ingest_path'] ?? '/api/ingest/gate');
        $path = '/'.ltrim($path, '/');
        if ($base === '') {
            return '';
        }

        return $base.$path;
    }
}
