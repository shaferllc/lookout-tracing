<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Recursively redact secret-bearing keys from arrays (breadcrumbs, span data,
 * context, stack-frame arguments).
 *
 * Two matching modes are combined:
 *  - exact key equality (case-insensitive) against {@see self::DEFAULT_KEYS}
 *    plus any host-configured extra keys;
 *  - substring matching against {@see self::DEFAULT_PATTERNS} plus host
 *    patterns, so keys like `db_password`, `app_key`, `client_secret`,
 *    `x-api-key`, or `stripe_secret` are caught even though they are not an
 *    exact match for a bare default key.
 *
 * Redaction always errs toward hiding: a false positive masks a harmless field,
 * a false negative leaks a credential. The substring list is deliberately
 * high-signal to keep false positives rare.
 *
 * The host app tunes the lists once at boot via {@see self::configure()} (wired
 * from `lookout-tracing.reporting.redaction.*`), so every call site — including
 * the framework-agnostic ones that only pass `$data` — inherits the same policy
 * without threading config through each call.
 */
final class DataRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * Exact key names (case-insensitive) that are always masked. Kept for
     * backward compatibility; substring patterns below cover most of these too.
     *
     * @var list<string>
     */
    private const DEFAULT_KEYS = [
        'password', 'passwd', 'secret', 'api_key', 'apikey', 'api-key',
        'authorization', 'auth', 'token', 'access_token', 'refresh_token',
        'bearer', 'cookie', 'set-cookie', 'credit_card', 'creditcard',
        'card_number', 'cvv', 'ssn', 'private_key',
    ];

    /**
     * Substrings that trigger redaction when contained anywhere in a (lowercased)
     * key. High-signal only — bare `auth`/`key` are intentionally omitted here
     * (they live in the exact list) to avoid masking `author`, `oauth`,
     * `foreign_key`, etc.
     *
     * @var list<string>
     */
    private const DEFAULT_PATTERNS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'authorization',
        'bearer', 'cookie', 'api_key', 'apikey', 'api-key', 'app_key',
        'appkey', 'access_key', 'secret_key', 'private_key', 'encryption_key',
        'client_secret', 'credit_card', 'creditcard', 'card_number',
        'cardnumber', 'cvv', 'ssn', 'signature', 'csrf', 'xsrf', 'dsn',
    ];

    /** @var list<string> */
    private static array $extraKeys = [];

    /** @var list<string> */
    private static array $extraPatterns = [];

    private static bool $scrubSql = true;

    /**
     * Set once at boot from config. Merged with the built-in defaults.
     *
     * @param  list<string>  $extraKeys      additional exact key names to mask
     * @param  list<string>  $extraPatterns  additional substrings to mask on
     */
    public static function configure(array $extraKeys = [], array $extraPatterns = [], bool $scrubSql = true): void
    {
        self::$extraKeys = array_values(array_filter(array_map(
            static fn ($k): string => strtolower(trim((string) $k)),
            $extraKeys,
        ), static fn (string $k): bool => $k !== ''));

        self::$extraPatterns = array_values(array_filter(array_map(
            static fn ($k): string => strtolower(trim((string) $k)),
            $extraPatterns,
        ), static fn (string $k): bool => $k !== ''));

        self::$scrubSql = $scrubSql;
    }

    /** Reset to built-in defaults (used in tests). */
    public static function reset(): void
    {
        self::$extraKeys = [];
        self::$extraPatterns = [];
        self::$scrubSql = true;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $extraKeys  additional exact key names for this call
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, array $extraKeys = []): array
    {
        $exact = [];
        foreach (array_merge(self::DEFAULT_KEYS, self::$extraKeys, $extraKeys) as $k) {
            $k = strtolower(trim((string) $k));
            if ($k !== '') {
                $exact[$k] = true;
            }
        }

        $patterns = array_values(array_unique(array_merge(self::DEFAULT_PATTERNS, self::$extraPatterns)));

        return self::walk($data, $exact, $patterns);
    }

    /**
     * True when a key name should be masked under the current policy.
     */
    public static function shouldRedactKey(string $key): bool
    {
        $lower = strtolower($key);
        $exact = array_merge(self::DEFAULT_KEYS, self::$extraKeys);
        foreach ($exact as $k) {
            if ($lower === strtolower(trim((string) $k))) {
                return true;
            }
        }
        foreach (array_merge(self::DEFAULT_PATTERNS, self::$extraPatterns) as $p) {
            $p = strtolower(trim((string) $p));
            if ($p !== '' && str_contains($lower, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask string/long-numeric literals in a SQL string so inline values (a
     * password or token written as a literal rather than a bound parameter)
     * don't leak through the query breadcrumb. Bindings are already excluded
     * upstream; this only touches literals embedded in the SQL text itself.
     */
    public static function scrubSql(string $sql): string
    {
        if (! self::$scrubSql) {
            return $sql;
        }

        // Single- or double-quoted string literals → ?
        $scrubbed = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", '?', $sql);
        if (! is_string($scrubbed)) {
            return $sql;
        }

        // Long standalone numeric literals (ids/timestamps are short; secrets/
        // card numbers tend to be long) → ?
        $scrubbed = preg_replace('/\b\d{7,}\b/', '?', $scrubbed);

        return is_string($scrubbed) ? $scrubbed : $sql;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, true>  $exact
     * @param  list<string>  $patterns
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, array $exact, array $patterns): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;
            if (self::keyBlocked($keyStr, $exact, $patterns)) {
                $out[$keyStr] = self::MASK;

                continue;
            }
            if (is_array($value)) {
                $out[$keyStr] = self::walk($value, $exact, $patterns);
            } else {
                $out[$keyStr] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $exact
     * @param  list<string>  $patterns
     */
    private static function keyBlocked(string $key, array $exact, array $patterns): bool
    {
        $lower = strtolower($key);
        if (isset($exact[$lower])) {
            return true;
        }
        foreach ($patterns as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }

        return false;
    }
}
