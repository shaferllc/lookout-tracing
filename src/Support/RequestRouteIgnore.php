<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Route-based ignore list for incoming requests: when a request's route matches any pattern,
 * the SDK never starts an http.server transaction for it. Patterns come from local config
 * (`performance.ignore_routes`, env LOOKOUT_PERFORMANCE_IGNORE_ROUTES) merged with the
 * dashboard's ignored-request-routes published in GET /api/config (`ignore_routes`).
 *
 * Matching semantics are a CONTRACT shared verbatim with the Lookout server
 * (App\Support\RequestRouteIgnore), which applies the same patterns at ingest. Keep both
 * implementations byte-for-byte identical.
 */
final class RequestRouteIgnore
{
    /**
     * Accepts a pattern list or a comma-separated string (env form) and returns a trimmed,
     * deduplicated list. Non-string entries are dropped so a malformed payload is safe.
     *
     * @return list<string>
     */
    public static function normalize(mixed $patterns): array
    {
        if (is_string($patterns)) {
            $patterns = explode(',', $patterns);
        }
        if (! is_array($patterns)) {
            return [];
        }

        $out = [];
        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $out[] = $pattern;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * True when any pattern matches the route name, the route URI, or the raw request path.
     * URI/path subjects are compared both with and without a leading slash so `/health*` and
     * `health*` behave the same; matching is case-insensitive with `*` wildcards.
     *
     * @param  list<string>  $patterns  Already-normalized patterns ({@see normalize()}).
     */
    public static function matches(array $patterns, ?string $routeName, ?string $routeUri, ?string $path): bool
    {
        if ($patterns === []) {
            return false;
        }

        $subjects = [];
        if (is_string($routeName) && $routeName !== '') {
            $subjects[] = $routeName;
        }
        foreach ([$routeUri, $path] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            $trimmed = ltrim($candidate, '/');
            $subjects[] = '/'.$trimmed;
            if ($trimmed !== '') {
                $subjects[] = $trimmed;
            }
        }
        if ($subjects === []) {
            return false;
        }
        $subjects = array_values(array_unique($subjects));

        foreach ($patterns as $pattern) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#i';
            foreach ($subjects as $subject) {
                if (preg_match($regex, $subject) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
