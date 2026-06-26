<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Tracer;
use Throwable;

/**
 * Faithful proxy around a Laravel filesystem disk that emits a child span for read/write/delete
 * operations (Laravel disks fire no native events, unlike cache/redis). Every {@see Cloud} contract
 * method is delegated to the wrapped disk, and any non-contract method (e.g. putFile, temporaryUrl,
 * download) is forwarded via {@see __call}, so wrapping a disk never changes its behaviour.
 *
 * Only path + disk + byte size + ok flag are recorded — never file contents.
 *
 * @mixin FilesystemAdapter
 */
final class TracingFilesystemAdapter implements Cloud
{
    private const MAX_PATH = 512;

    public function __construct(
        private readonly Filesystem $inner,
        private readonly string $disk,
    ) {}

    /**
     * Forward any method not explicitly proxied (putFile macros, temporaryUrl, mimeType, …) to the
     * wrapped disk untraced, so the decorator stays a complete stand-in for the real adapter.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function get($path)
    {
        $start = microtime(true);
        try {
            $result = $this->inner->get($path);
        } catch (Throwable $e) {
            $this->emit(SpanOperation::FILE_READ, (string) $path, $start, ['file.ok' => false], 'internal_error');
            throw $e;
        }

        $data = ['file.ok' => true];
        if (is_string($result)) {
            $data['file.bytes'] = strlen($result);
        }
        $this->emit(SpanOperation::FILE_READ, (string) $path, $start, $data, null);

        return $result;
    }

    public function readStream($path)
    {
        return $this->trace(SpanOperation::FILE_READ, (string) $path, [], fn () => $this->inner->readStream($path));
    }

    public function put($path, $contents, $options = [])
    {
        $data = is_string($contents) ? ['file.bytes' => strlen($contents)] : [];

        return $this->trace(SpanOperation::FILE_WRITE, (string) $path, $data, fn () => $this->inner->put($path, $contents, $options));
    }

    public function putFile($path, $file = null, $options = [])
    {
        return $this->trace(SpanOperation::FILE_WRITE, (string) $path, [], fn () => $this->inner->putFile($path, $file, $options));
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->trace(SpanOperation::FILE_WRITE, (string) $path, [], fn () => $this->inner->putFileAs($path, $file, $name, $options));
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->trace(SpanOperation::FILE_WRITE, (string) $path, [], fn () => $this->inner->writeStream($path, $resource, $options));
    }

    public function prepend($path, $data)
    {
        return $this->trace(SpanOperation::FILE_WRITE, (string) $path, [], fn () => $this->inner->prepend($path, $data));
    }

    public function append($path, $data)
    {
        return $this->trace(SpanOperation::FILE_WRITE, (string) $path, [], fn () => $this->inner->append($path, $data));
    }

    public function copy($from, $to)
    {
        return $this->trace(SpanOperation::FILE_WRITE, $from.' → '.$to, ['file.target' => self::truncate((string) $to)], fn () => $this->inner->copy($from, $to));
    }

    public function move($from, $to)
    {
        return $this->trace(SpanOperation::FILE_WRITE, $from.' → '.$to, ['file.target' => self::truncate((string) $to)], fn () => $this->inner->move($from, $to));
    }

    public function delete($paths)
    {
        $label = is_array($paths) ? implode(', ', array_map('strval', $paths)) : (string) $paths;

        return $this->trace(SpanOperation::FILE_DELETE, $label, [], fn () => $this->inner->delete($paths));
    }

    public function path($path)
    {
        return $this->inner->path($path);
    }

    public function exists($path)
    {
        return $this->inner->exists($path);
    }

    public function getVisibility($path)
    {
        return $this->inner->getVisibility($path);
    }

    public function setVisibility($path, $visibility)
    {
        return $this->inner->setVisibility($path, $visibility);
    }

    public function size($path)
    {
        return $this->inner->size($path);
    }

    public function lastModified($path)
    {
        return $this->inner->lastModified($path);
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->inner->files($directory, $recursive);
    }

    public function allFiles($directory = null)
    {
        return $this->inner->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->inner->directories($directory, $recursive);
    }

    public function allDirectories($directory = null)
    {
        return $this->inner->allDirectories($directory);
    }

    public function makeDirectory($path)
    {
        return $this->inner->makeDirectory($path);
    }

    public function deleteDirectory($directory)
    {
        return $this->inner->deleteDirectory($directory);
    }

    public function url($path)
    {
        return $this->inner->url($path);
    }

    /**
     * Time a delegated operation and emit a span for it, re-throwing on failure.
     *
     * @param  array<string, mixed>  $data
     */
    private function trace(string $op, string $description, array $data, callable $run): mixed
    {
        $start = microtime(true);
        try {
            $result = $run();
        } catch (Throwable $e) {
            $this->emit($op, $description, $start, $data + ['file.ok' => false], 'internal_error');
            throw $e;
        }

        $this->emit($op, $description, $start, $data + ['file.ok' => true], null);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $op, string $description, float $start, array $data, ?string $status): void
    {
        $tracer = Tracer::instance();
        $parent = $tracer->getCurrentSpan();
        if ($parent === null || $parent->isFinished() || ! $tracer->isSpanRecordingEnabled()) {
            return;
        }

        $now = microtime(true);
        $data['file.disk'] = $this->disk;
        $data['file.duration_ms'] = max(0.0, round(($now - $start) * 1000, 3));

        $child = $parent->startChild($op, self::truncate($description), $start);
        $child->setData($data);
        if ($status !== null) {
            $child->setStatus($status);
        }
        $child->finish($now);
    }

    private static function truncate(string $value): string
    {
        return strlen($value) > self::MAX_PATH ? substr($value, 0, self::MAX_PATH) : $value;
    }
}
