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
    public static function stackFramesFromThrowable(Throwable $e, int $maxFrames = 200, bool $includeArguments = false): array
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
            if ($includeArguments && isset($t['args']) && is_array($t['args'])) {
                $args = self::normalizeTraceArgs($t['args']);
                if ($args !== []) {
                    $row['args'] = $args;
                }
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

    /**
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    private static function normalizeTraceArgs(array $args): array
    {
        $out = [];
        $maxPositional = 16;
        $n = 0;
        foreach ($args as $v) {
            if ($n >= $maxPositional) {
                $out[] = '…';
                break;
            }
            $out[] = self::normalizeTraceValue($v, 0, 5);
            $n++;
        }

        return $out;
    }

    private static function normalizeTraceValue(mixed $value, int $depth, int $maxDepth): mixed
    {
        if ($depth >= $maxDepth) {
            return '[max depth]';
        }
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            $s = $value;

            return strlen($s) > 512 ? substr($s, 0, 509).'…' : $s;
        }
        if ($value instanceof Throwable) {
            return $value::class.': '.substr($value->getMessage(), 0, 200);
        }
        if (is_object($value)) {
            return $value::class;
        }
        if (! is_array($value)) {
            return '[unsupported]';
        }
        $isList = array_is_list($value);
        $out = [];
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i >= 32) {
                $out['…'] = 'truncated';
                break;
            }
            $key = $isList ? $i : (is_string($k) ? substr($k, 0, 64) : (string) $k);
            $out[$key] = self::normalizeTraceValue($v, $depth + 1, $maxDepth);
            $i++;
        }

        return $out;
    }
}
