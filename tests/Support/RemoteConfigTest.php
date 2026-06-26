<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Support;

use Lookout\Tracing\Support\RemoteConfig;
use PHPUnit\Framework\TestCase;

final class RemoteConfigTest extends TestCase
{
    public function test_auth_and_filesystem_signals_map_to_their_config_paths(): void
    {
        $map = RemoteConfig::enabledMap();

        $this->assertSame('auth_monitoring.enabled', $map['auth'] ?? null);
        $this->assertSame('filesystem_monitoring.enabled', $map['filesystem'] ?? null);
    }

    public function test_enabled_overrides_turn_auth_and_filesystem_on_from_the_dashboard(): void
    {
        $remote = [
            'signals' => [
                'auth' => ['enabled' => true, 'sample_rate' => 1.0],
                'filesystem' => ['enabled' => true, 'sample_rate' => 1.0],
            ],
        ];

        $overrides = RemoteConfig::enabledOverrides($remote);

        $this->assertTrue($overrides['auth_monitoring.enabled']);
        $this->assertTrue($overrides['filesystem_monitoring.enabled']);
    }

    public function test_enabled_overrides_turn_them_off_when_the_dashboard_disables_them(): void
    {
        $remote = [
            'signals' => [
                'auth' => ['enabled' => false],
                'filesystem' => ['enabled' => false],
            ],
        ];

        $overrides = RemoteConfig::enabledOverrides($remote);

        $this->assertFalse($overrides['auth_monitoring.enabled']);
        $this->assertFalse($overrides['filesystem_monitoring.enabled']);
    }

    public function test_missing_signals_are_not_forced(): void
    {
        // A partial/old payload must never force auth/filesystem on or off.
        $overrides = RemoteConfig::enabledOverrides(['signals' => []]);

        $this->assertArrayNotHasKey('auth_monitoring.enabled', $overrides);
        $this->assertArrayNotHasKey('filesystem_monitoring.enabled', $overrides);
    }
}
