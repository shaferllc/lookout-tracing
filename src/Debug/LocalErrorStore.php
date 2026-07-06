<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Throwable;

/**
 * On-disk history of locally-rendered debug pages (storage/framework/lookout/
 * errors). Every rendered page saves its payload + request details so
 * /_lookout/errors can list recent errors and re-render any of them — a
 * Telescope-lite that works offline and never leaves the machine.
 */
final class LocalErrorStore
{
    private const MAX_ENTRIES = 50;

    public function __construct(private readonly ?string $directory = null) {}

    public function enabled(): bool
    {
        return $this->dir() !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $requestDetails
     */
    public function save(array $payload, array $requestDetails = []): void
    {
        $dir = $this->dir();
        if ($dir === null) {
            return;
        }

        $id = $payload['occurrence_uuid'] ?? null;
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $id)) {
            return;
        }

        try {
            $encoded = json_encode([
                'saved_at' => time(),
                'payload' => $payload,
                'request_details' => $requestDetails,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            if (! is_string($encoded)) {
                return;
            }
            @file_put_contents($dir.'/'.$id.'.json', $encoded, LOCK_EX);
            $this->prune($dir);
        } catch (Throwable) {
            // history is best-effort; never interfere with error rendering
        }
    }

    /**
     * Newest-first summaries for the history index.
     *
     * @return list<array{id: string, saved_at: int, class: string, message: string, url: string}>
     */
    public function list(): array
    {
        $dir = $this->dir();
        if ($dir === null) {
            return [];
        }

        $entries = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $row = $this->read($file);
            if ($row === null) {
                continue;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $entries[] = [
                'id' => basename($file, '.json'),
                'saved_at' => (int) ($row['saved_at'] ?? 0),
                'class' => is_string($payload['exception_class'] ?? null) ? $payload['exception_class'] : 'Exception',
                'message' => is_string($payload['message'] ?? null) ? $payload['message'] : '',
                'url' => is_string($payload['url'] ?? null) ? $payload['url'] : '',
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $b['saved_at'] <=> $a['saved_at']);

        return $entries;
    }

    /**
     * @return array{payload: array<string, mixed>, request_details: array<string, mixed>}|null
     */
    public function get(string $id): ?array
    {
        $dir = $this->dir();
        if ($dir === null || ! preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $id)) {
            return null;
        }
        $row = $this->read($dir.'/'.$id.'.json');
        if ($row === null || ! is_array($row['payload'] ?? null)) {
            return null;
        }

        return [
            'payload' => $row['payload'],
            'request_details' => is_array($row['request_details'] ?? null) ? $row['request_details'] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        try {
            $raw = @file_get_contents($file);
            if (! is_string($raw)) {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function prune(string $dir): void
    {
        $files = glob($dir.'/*.json') ?: [];
        if (count($files) <= self::MAX_ENTRIES) {
            return;
        }
        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach (array_slice($files, self::MAX_ENTRIES) as $stale) {
            @unlink($stale);
        }
    }

    private function dir(): ?string
    {
        if ($this->directory !== null) {
            return is_dir($this->directory) || @mkdir($this->directory, 0755, true) ? $this->directory : null;
        }
        if (! function_exists('storage_path')) {
            return null;
        }

        try {
            $dir = storage_path('framework/lookout/errors');
        } catch (Throwable) {
            return null;
        }

        return is_dir($dir) || @mkdir($dir, 0755, true) ? $dir : null;
    }
}
