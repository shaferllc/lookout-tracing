<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Support;

use Lookout\Tracing\Support\IngestSelfMonitoring;
use PHPUnit\Framework\TestCase;

final class IngestSelfMonitoringTest extends TestCase
{
    public function test_is_ingest_path(): void
    {
        $this->assertTrue(IngestSelfMonitoring::isIngestPath('api/ingest'));
        $this->assertTrue(IngestSelfMonitoring::isIngestPath('api/ingest/trace'));
        $this->assertTrue(IngestSelfMonitoring::isIngestPath('/api/ingest/rum'));
        $this->assertFalse(IngestSelfMonitoring::isIngestPath('dashboard'));
        $this->assertFalse(IngestSelfMonitoring::isIngestPath('api/v1/projects'));
    }

    public function test_should_skip_monitoring_for_ingest_path(): void
    {
        $this->assertTrue(IngestSelfMonitoring::shouldSkipMonitoringForPath('api/ingest/trace'));
        $this->assertFalse(IngestSelfMonitoring::shouldSkipMonitoringForPath('projects/1'));
    }

    public function test_internal_header_skips_monitoring(): void
    {
        $this->assertTrue(IngestSelfMonitoring::shouldSkipMonitoringForPath('dashboard', true));
        $this->assertFalse(IngestSelfMonitoring::shouldSkipMonitoringForPath('dashboard', false));
    }

    public function test_same_host_ingest_url(): void
    {
        putenv('APP_URL=https://lookout.test');
        $this->assertTrue(IngestSelfMonitoring::isSameHostIngestUrl('https://lookout.test/api/ingest/trace'));
        $this->assertFalse(IngestSelfMonitoring::isSameHostIngestUrl('https://other.test/api/ingest/trace'));
        putenv('APP_URL');
    }

    public function test_internal_header_lines_only_for_same_host(): void
    {
        putenv('APP_URL=https://lookout.test');
        $lines = IngestSelfMonitoring::internalIngestHeaderLines('https://lookout.test/api/ingest/log');
        $this->assertSame(['X-Lookout-Ingest-Internal: 1'], $lines);
        $this->assertSame([], IngestSelfMonitoring::internalIngestHeaderLines('https://remote.test/api/ingest/log'));
        putenv('APP_URL');
    }
}
