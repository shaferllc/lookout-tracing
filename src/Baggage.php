<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Parses and builds W3C-style baggage header lists (comma-separated key=value members).
 * Sentry adds keys like sentry-trace_id, sentry-environment, sentry-release, etc.
 *
 * @see https://www.w3.org/TR/baggage/
 * @see https://docs.sentry.io/platforms/php/tracing/trace-propagation/
 */
final class Baggage
{
    /**
     * @return array<string, string>
     */
    public static function parse(?string $header): array
    {
        if ($header === null) {
            return [];
        }
        $header = trim($header);
        if ($header === '') {
            return [];
        }

        $out = [];
        foreach (self::splitMembers($header) as $member) {
            $eq = strpos($member, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($member, 0, $eq));
            $value = trim(substr($member, $eq + 1));
            if ($key === '') {
                continue;
            }
            $out[$key] = rawurldecode($value);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public static function build(array $entries): string
    {
        $parts = [];
        foreach ($entries as $k => $v) {
            $k = trim((string) $k);
            if ($k === '') {
                continue;
            }
            $parts[] = $k.'='.rawurlencode((string) $v);
        }

        return implode(',', $parts);
    }

    /**
     * Merge later entries over earlier; last wins per key.
     *
     * @param  array<string, string>  ...$maps
     * @return array<string, string>
     */
    public static function merge(array ...$maps): array
    {
        $out = [];
        foreach ($maps as $map) {
            foreach ($map as $k => $v) {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * Split on commas that are not inside percent-encoded sequences is complex;
     * Sentry baggage rarely contains literal commas in values. Simple split.
     *
     * @return list<string>
     */
    private static function splitMembers(string $header): array
    {
        $parts = preg_split('/\s*,\s*/', $header) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $s) => $s !== ''));
    }
}
