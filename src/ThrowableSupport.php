<?php

declare(strict_types=1);

namespace Lookout\Tracing;

use Throwable;

/**
 * Stack frame extraction for error ingest payloads.
 */
final class ThrowableSupport
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function stackFramesFromThrowable(Throwable $e, int $maxFrames = 200): array
    {
        $out = [];
        $i = 0;
        foreach ($e->getTrace() as $t) {
            if (! is_array($t)) {
                continue;
            }
            $row = ['index' => $i];
            if (isset($t['file']) && is_string($t['file']) && $t['file'] !== '') {
                $row['file'] = substr($t['file'], 0, 4096);
            }
            if (isset($t['line'])) {
                $row['line'] = (int) $t['line'];
            }
            if (isset($t['class']) && is_string($t['class']) && $t['class'] !== '') {
                $row['class'] = substr($t['class'], 0, 512);
            }
            if (isset($t['function']) && is_string($t['function']) && $t['function'] !== '') {
                $row['function'] = substr($t['function'], 0, 256);
            }
            if (isset($t['type']) && is_string($t['type']) && $t['type'] !== '') {
                $row['type'] = substr($t['type'], 0, 8);
            }
            if (isset($row['file']) || isset($row['function'])) {
                $out[] = $row;
            }
            $i++;
            if ($i >= $maxFrames) {
                break;
            }
        }

        return $out;
    }
}
