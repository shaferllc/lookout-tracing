<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use SplFileObject;
use Throwable;

/**
 * Enriches stack frames with local source context (the lines around each
 * frame's file:line) for the on-box debug page.
 *
 * This runs ONLY in the local render path — the resolved source is never added
 * to the ingest payload and never leaves the machine. It is the reason the
 * debug page can show Ignition-style code snippets while the data sent to
 * Lookout stays lean and source-free.
 */
final class FrameSourceResolver
{
    /** Lines of context to show on each side of the offending line. */
    private const CONTEXT_RADIUS = 10;

    /** Don't read files larger than this (avoid slurping huge generated files). */
    private const MAX_FILE_BYTES = 3_000_000;

    /** Only resolve source for the first N frames — the top of the stack is what matters. */
    private const MAX_ENRICHED_FRAMES = 60;

    /**
     * Return a copy of the frames with `pre_context` / `context_line` /
     * `post_context` (and `context_start_line`) added where the source file is
     * readable. Frames are otherwise passed through untouched.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    public function enrich(array $frames): array
    {
        $out = [];
        $enriched = 0;
        foreach ($frames as $frame) {
            if ($enriched < self::MAX_ENRICHED_FRAMES) {
                $withSource = $this->resolveFrame($frame);
                if ($withSource !== $frame) {
                    $enriched++;
                }
                $out[] = $withSource;
            } else {
                $out[] = $frame;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    private function resolveFrame(array $frame): array
    {
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;
        if (! is_string($file) || $file === '' || ! is_int($line) && ! (is_string($line) && ctype_digit($line))) {
            return $frame;
        }
        $line = (int) $line;
        if ($line < 1 || ! is_file($file) || ! is_readable($file)) {
            return $frame;
        }
        $size = @filesize($file);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return $frame;
        }

        $window = $this->readWindow($file, $line);
        if ($window === null) {
            return $frame;
        }

        return array_merge($frame, $window);
    }

    /**
     * Read the line window around $line without loading the whole file into an
     * array when it's large.
     *
     * @return array{pre_context: list<string>, context_line: string, post_context: list<string>, context_start_line: int}|null
     */
    private function readWindow(string $file, int $line): ?array
    {
        $start = max(1, $line - self::CONTEXT_RADIUS);
        $end = $line + self::CONTEXT_RADIUS;

        try {
            $handle = new SplFileObject($file, 'r');
        } catch (Throwable) {
            return null;
        }

        $pre = [];
        $target = null;
        $post = [];

        $handle->seek($start - 1); // 0-indexed
        for ($n = $start; $n <= $end; $n++) {
            if ($handle->eof()) {
                break;
            }
            $raw = $handle->current();
            $handle->next();
            if (! is_string($raw)) {
                continue;
            }
            $text = rtrim($raw, "\r\n");
            if ($n < $line) {
                $pre[] = $text;
            } elseif ($n === $line) {
                $target = $text;
            } else {
                $post[] = $text;
            }
        }

        if ($target === null) {
            return null;
        }

        return [
            'pre_context' => $pre,
            'context_line' => $target,
            'post_context' => $post,
            'context_start_line' => $start,
        ];
    }
}
