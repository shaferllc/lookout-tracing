<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Normalizes SQL for duplicate / N+1 style counting (not for security or exact matching).
 */
final class SqlFingerprint
{
    public static function normalize(string $sql): string
    {
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql) ?? $sql;
        $s = preg_replace('/\b\d+\b/', '?', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return strtolower(trim($s));
    }
}
