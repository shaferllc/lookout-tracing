<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Laravel;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Lookout\Tracing\Laravel\PerformanceMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Transaction naming: route name > route URI > raw path — EXCEPT the synthetic
 * `generated::<hash>` names route:cache assigns to unnamed routes, which would make every
 * unnamed endpoint show up as noise like "POST generated::k0NJeBEPEeLqq1a8" in the dashboard.
 */
final class PerformanceMiddlewareTransactionNameTest extends TestCase
{
    private function transactionName(Request $request): string
    {
        $method = new ReflectionMethod(PerformanceMiddleware::class, 'transactionName');

        return $method->invoke(null, $request);
    }

    private function requestWithRoute(string $method, string $uri, ?string $routeName): Request
    {
        $route = new Route([$method], ltrim($uri, '/'), []);
        if ($routeName !== null) {
            $route->name($routeName);
        }

        $request = Request::create($uri, $method);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }

    public function test_named_routes_use_the_route_name(): void
    {
        $request = $this->requestWithRoute('GET', '/api/users/1', 'api.users.show');

        $this->assertSame('GET api.users.show', $this->transactionName($request));
    }

    public function test_generated_route_cache_names_fall_back_to_the_uri(): void
    {
        $request = $this->requestWithRoute('POST', '/api/metrics', 'generated::k0NJeBEPEeLqq1a8');

        $this->assertSame('POST /api/metrics', $this->transactionName($request));
    }

    public function test_unnamed_routes_use_the_uri(): void
    {
        $request = $this->requestWithRoute('POST', '/api/metrics', null);

        $this->assertSame('POST /api/metrics', $this->transactionName($request));
    }
}
