<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Client suppression key: a small, stable identifier for an error derived only from its exception
 * class and a normalized message. When a user ignores an error group in the dashboard, the server
 * publishes that group's key in GET /api/config (`suppress`); the SDK computes the same key for each
 * exception and drops matches before sending, so ignored errors stop re-ingesting.
 *
 * This recipe is a CONTRACT shared verbatim with the Lookout server (App\Support\ErrorSuppressionKey)
 * and the other SDKs. It MUST stay byte-for-byte identical across all of them — changing it silently
 * breaks suppression matching, so bump the version tag (`lkt_supp_v1`) and update every side together.
 */
final class ErrorSuppressionKey
{
    private const VERSION = 'lkt_supp_v1';

    /**
     * Stable 32-char key for an exception class + message pair. Null/empty inputs are treated as ''.
     */
    public static function compute(?string $exceptionClass, ?string $message): string
    {
        $class = mb_strtolower(trim((string) $exceptionClass));
        $normalized = self::normalizeMessage((string) $message);

        return substr(hash('sha256', self::VERSION.'|'.$class.'|'.$normalized), 0, 32);
    }

    /**
     * Lowercase, collapse whitespace, and mask volatile tokens (UUIDs, long id/hash runs, numbers)
     * so occurrences of the same error that differ only in a path id, count, or timestamp collapse
     * to one key. Regexes run in a fixed order and MUST match the server implementation exactly.
     */
    public static function normalizeMessage(string $message): string
    {
        $s = mb_strtolower(trim($message));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        // UUIDs first (their dashes hide them from the long-run rule below).
        $s = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<id>', $s) ?? $s;
        // Long alphanumeric runs: ULIDs, hashes, tokens (12+ chars).
        $s = preg_replace('/[0-9a-z]{12,}/', '<id>', $s) ?? $s;
        // Hex literals, then plain integers/decimals.
        $s = preg_replace('/\b0x[0-9a-f]+\b/', '<n>', $s) ?? $s;
        $s = preg_replace('/\d+/', '<n>', $s) ?? $s;

        return mb_substr(trim($s), 0, 200);
    }
}
