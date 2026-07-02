<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Support;

use Lookout\Tracing\Support\RemoteConfig;
use Lookout\Tracing\Support\RequestRouteIgnore;
use PHPUnit\Framework\TestCase;

final class RequestRouteIgnoreTest extends TestCase
{
    public function test_normalize_accepts_arrays_and_env_style_comma_strings(): void
    {
        $this->assertSame(['horizon.*', '/health'], RequestRouteIgnore::normalize(['horizon.*', ' /health ', '', 42, null, 'horizon.*']));
        $this->assertSame(['horizon.*', '/up'], RequestRouteIgnore::normalize('horizon.*, /up ,'));
        $this->assertSame([], RequestRouteIgnore::normalize(null));
        $this->assertSame([], RequestRouteIgnore::normalize(3.14));
    }

    public function test_matches_route_name_uri_and_path_with_wildcards(): void
    {
        $this->assertTrue(RequestRouteIgnore::matches(['horizon.*'], 'horizon.stats.index', null, null));
        $this->assertTrue(RequestRouteIgnore::matches(['/health*'], null, 'health/live', null));
        $this->assertTrue(RequestRouteIgnore::matches(['health*'], null, '/health/live', null));
        $this->assertTrue(RequestRouteIgnore::matches(['/up'], null, null, 'up'));
        $this->assertFalse(RequestRouteIgnore::matches(['/up'], null, null, '/upload'));
        $this->assertFalse(RequestRouteIgnore::matches([], 'horizon.index', '/h', '/h'));
    }

    public function test_matching_is_case_insensitive_and_regex_safe(): void
    {
        $this->assertTrue(RequestRouteIgnore::matches(['HORIZON.*'], 'horizon.index', null, null));
        $this->assertTrue(RequestRouteIgnore::matches(['/api/v1.0/ping'], null, null, '/api/v1.0/ping'));
        $this->assertFalse(RequestRouteIgnore::matches(['/api/v1.0/ping'], null, null, '/api/v1x0/ping'));
    }

    public function test_remote_config_extracts_ignore_routes_and_skips_junk(): void
    {
        $this->assertSame(
            ['horizon.*', '/health*'],
            RemoteConfig::ignoredRoutes(['ignore_routes' => ['horizon.*', ' /health* ', '', 12, null]]),
        );
        $this->assertSame([], RemoteConfig::ignoredRoutes([]));
        $this->assertSame([], RemoteConfig::ignoredRoutes(['ignore_routes' => 'not-a-list-in-remote-doc']));
    }
}
