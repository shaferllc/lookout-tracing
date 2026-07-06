<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Illuminate\Routing\Router;
use Throwable;

/**
 * "Did you mean" candidates for the smart 404 page: registered GET routes
 * ranked by edit distance / prefix affinity to the requested path
 * (Symfony-style).
 */
final class RouteSuggestions
{
    private const MAX_SUGGESTIONS = 6;

    /**
     * @param  list<string>  $uris  registered route URIs (without leading slash)
     * @return list<array{uri: string, score: float}>
     */
    public static function rank(string $requestedPath, array $uris): array
    {
        $path = trim($requestedPath, '/');
        if ($path === '') {
            return [];
        }

        $scored = [];
        foreach (array_unique($uris) as $uri) {
            if (! is_string($uri) || $uri === '' || str_contains($uri, '_lookout')) {
                continue;
            }
            $candidate = trim($uri, '/');
            if ($candidate === '') {
                continue;
            }

            $distance = levenshtein(substr($path, 0, 120), substr($candidate, 0, 120));
            $max = max(strlen($path), strlen($candidate));
            $similarity = $max > 0 ? 1 - ($distance / $max) : 0;

            // Sharing a first segment is a strong hint (typo'd tail).
            $pathHead = explode('/', $path)[0];
            $candidateHead = explode('/', $candidate)[0];
            if ($pathHead !== '' && $pathHead === $candidateHead) {
                $similarity += 0.25;
            }

            if ($similarity >= 0.45) {
                $scored[] = ['uri' => '/'.$candidate, 'score' => round($similarity, 3)];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Collect GET route URIs from the router, excluding vendor-ish internals.
     *
     * @return list<string>
     */
    public static function registeredGetUris(): array
    {
        if (! function_exists('app')) {
            return [];
        }

        try {
            /** @var Router $router */
            $router = app('router');
            $uris = [];
            foreach ($router->getRoutes()->getRoutes() as $route) {
                if (in_array('GET', $route->methods(), true)) {
                    $uris[] = $route->uri();
                }
            }

            return $uris;
        } catch (Throwable) {
            return [];
        }
    }
}
