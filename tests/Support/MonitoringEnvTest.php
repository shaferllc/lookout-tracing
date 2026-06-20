<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Support;

use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Support\MonitoringEnv;
use PHPUnit\Framework\TestCase;

final class MonitoringEnvTest extends TestCase
{
    public function test_resolve_enabled_uses_explicit_value(): void
    {
        $this->assertTrue(MonitoringEnv::resolveEnabled('true', false));
        $this->assertFalse(MonitoringEnv::resolveEnabled('false', true));
        $this->assertFalse(MonitoringEnv::resolveEnabled('0', true));
    }

    public function test_resolve_enabled_falls_back_to_quick_start_default(): void
    {
        $this->assertTrue(MonitoringEnv::resolveEnabled(null, true));
        $this->assertFalse(MonitoringEnv::resolveEnabled(null, false));
        $this->assertTrue(MonitoringEnv::resolveEnabled('', true));
    }

    public function test_quick_start_env_lines_include_all_primary_watchers(): void
    {
        $lines = MonitoringEnv::quickStartEnvLines();
        $joined = implode("\n", $lines);

        foreach ([
            'LOOKOUT_LARAVEL=true',
            'LOOKOUT_JOB_MONITORING_ENABLED=true',
            'LOOKOUT_MAIL_MONITORING_ENABLED=true',
            'LOOKOUT_EVENT_MONITORING_ENABLED=true',
            'LOOKOUT_NOTIFICATION_MONITORING_ENABLED=true',
            'LOOKOUT_MODEL_MONITORING_ENABLED=true',
            'LOOKOUT_CRON_MONITORING_ENABLED=true',
            'LOOKOUT_PERFORMANCE_ENABLED=true',
            'LOOKOUT_LOGS_ENABLED=true',
            'LOOKOUT_METRICS_ENABLED=true',
            'LOOKOUT_RUM_ENABLED=true',
        ] as $needle) {
            $this->assertStringContainsString($needle, $joined, "Missing {$needle}");
        }
    }

    public function test_catalog_covers_core_ui_watchers(): void
    {
        $catalog = MonitoringEnv::catalog();

        foreach (['issues', 'requests', 'jobs', 'schedule', 'logs', 'mail', 'models', 'metrics'] as $key) {
            $this->assertArrayHasKey($key, $catalog);
            $this->assertNotSame('', $catalog[$key]['primary_env']);
        }
    }

    public function test_cron_client_respects_enabled_flag(): void
    {
        CronClient::resetForTesting();
        CronClient::configure([
            'enabled' => false,
            'api_key' => 'test-key',
            'base_uri' => 'https://lookout.test',
        ]);

        $this->assertNull(CronClient::captureCheckIn('demo', 'ok'));
        $this->assertSame('done', CronClient::withMonitor('demo', static fn (): string => 'done'));
    }
}
