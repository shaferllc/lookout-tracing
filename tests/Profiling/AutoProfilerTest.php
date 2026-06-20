<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Profiling;

use Lookout\Tracing\Profiling\AutoProfiler;
use Lookout\Tracing\Profiling\ProfileClient;
use Lookout\Tracing\Profiling\ProfileIngestClient;
use PHPUnit\Framework\TestCase;

final class AutoProfilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AutoProfiler::resetForTesting();
        ProfileClient::resetForTesting();
        ProfileIngestClient::resetForTesting();
        ProfileIngestClient::configure(['manual_pulse_fallback' => false]);
    }

    protected function tearDown(): void
    {
        AutoProfiler::resetForTesting();
        ProfileClient::resetForTesting();
        ProfileIngestClient::resetForTesting();
        parent::tearDown();
    }

    public function test_is_noop_without_excimer(): void
    {
        AutoProfiler::configure(['enabled' => true, 'sample_rate' => 1.0]);
        AutoProfiler::forceSampleFractionForTesting(0.0); // would always sample

        AutoProfiler::maybeStart();

        $this->assertFalse(AutoProfiler::isRunning(), 'must not start without the Excimer extension');
        $this->assertFalse(AutoProfiler::finishAndSend(), 'nothing to send when no capture is running');
    }

    public function test_does_not_start_when_disabled(): void
    {
        AutoProfiler::configure(['enabled' => false, 'sample_rate' => 1.0]);
        AutoProfiler::forceSampleFractionForTesting(0.0);

        AutoProfiler::maybeStart();

        $this->assertFalse(AutoProfiler::isRunning());
    }

    public function test_sample_rate_threshold_excludes_high_draw(): void
    {
        AutoProfiler::configure(['enabled' => true, 'sample_rate' => 0.25]);
        AutoProfiler::forceSampleFractionForTesting(0.5); // 0.5 >= 0.25 -> not sampled

        AutoProfiler::maybeStart();

        $this->assertFalse(AutoProfiler::isRunning());
    }

    public function test_build_payload_merges_context_and_config_defaults(): void
    {
        AutoProfiler::configure(['environment' => 'production', 'release' => '1.2.3']);

        $payload = AutoProfiler::buildPayload($this->fakeLog(), [
            'trace_id' => 'abc123',
            'transaction' => 'GET /x',
        ]);

        $this->assertSame('excimer', $payload['agent']);
        $this->assertSame('speedscope', $payload['format']);
        $this->assertIsArray($payload['data']);
        $this->assertSame('abc123', $payload['trace_id']);
        $this->assertSame('GET /x', $payload['transaction']);
        $this->assertSame('production', $payload['environment']);
        $this->assertSame('1.2.3', $payload['release']);
    }

    public function test_caller_context_overrides_config_defaults(): void
    {
        AutoProfiler::configure(['environment' => 'production', 'release' => '1.0.0']);

        $payload = AutoProfiler::buildPayload($this->fakeLog(), ['environment' => 'staging']);

        $this->assertSame('staging', $payload['environment']);
        $this->assertSame('1.0.0', $payload['release']);
    }

    public function test_build_and_send_returns_false_and_never_throws_when_unconfigured(): void
    {
        // ProfileClient has no base_uri/api_key -> sendProfile() returns false without any network call.
        $this->assertFalse(AutoProfiler::buildAndSend($this->fakeLog(), ['trace_id' => 'x']));
    }

    /**
     * Excimer-shaped log: only getSpeedscopeData() is required by ExcimerExporter.
     */
    private function fakeLog(): object
    {
        return new class
        {
            /** @return array<string, mixed> */
            public function getSpeedscopeData(): array
            {
                return [
                    '$schema' => 'https://www.speedscope.app/file-format-schema.json',
                    'profiles' => [],
                    'shared' => ['frames' => []],
                ];
            }
        };
    }
}
