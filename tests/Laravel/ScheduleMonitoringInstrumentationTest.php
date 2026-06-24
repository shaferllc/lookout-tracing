<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Laravel;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Events\Dispatcher;
use Lookout\Tracing\Cron\CheckInStatus;
use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Cron\MonitorConfig;
use Lookout\Tracing\Laravel\ScheduleMonitoringInstrumentation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ScheduleMonitoringInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CronClient::resetForTesting(); // unconfigured => captureCheckIn returns null with no network call
        ScheduleMonitoringInstrumentation::resetForTesting();
    }

    protected function tearDown(): void
    {
        ScheduleMonitoringInstrumentation::resetForTesting();
        CronClient::resetForTesting();
        parent::tearDown();
    }

    public function test_register_wires_scheduler_listeners(): void
    {
        $dispatcher = new Dispatcher;
        ScheduleMonitoringInstrumentation::register($dispatcher, 5);

        $this->assertTrue($dispatcher->hasListeners(ScheduledTaskStarting::class));
    }

    public function test_slug_prefers_description_then_artisan_command(): void
    {
        $withDescription = $this->fakeTask(command: "'php' 'artisan' inspire", description: 'Nightly digest');
        $this->assertSame('nightly-digest', $this->slugFor($withDescription));

        $artisanCommand = $this->fakeTask(command: "'/usr/bin/php8.3' 'artisan' backup:run --force");
        $this->assertSame('backup-run', $this->slugFor($artisanCommand));
    }

    public function test_slug_falls_back_to_mutex_hash_when_no_identity(): void
    {
        $bare = $this->fakeTask(command: '');
        $slug = $this->slugFor($bare);

        $this->assertStringStartsWith('task-', $slug);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $slug);
    }

    public function test_config_carries_crontab_schedule_timezone_and_margin(): void
    {
        ScheduleMonitoringInstrumentation::register(new Dispatcher, 7);
        $task = $this->fakeTask(command: "'php' 'artisan' report", expression: '0 3 * * *', timezone: 'America/New_York');

        $config = $this->configFor($task);
        $this->assertInstanceOf(MonitorConfig::class, $config);

        $array = $config->toArray();
        $this->assertSame(['type' => 'crontab', 'crontab' => '0 3 * * *'], $array['schedule']);
        $this->assertSame('America/New_York', $array['timezone']);
        $this->assertSame(7, $array['checkin_margin']);
    }

    public function test_config_is_null_without_an_expression(): void
    {
        $task = $this->fakeTask(command: "'php' 'artisan' adhoc", expression: '');
        $this->assertNull($this->configFor($task));
    }

    public function test_successful_run_pairs_start_and_finish(): void
    {
        $task = $this->fakeTask(command: "'php' 'artisan' ok-job");

        $this->invoke('starting', $task);
        $this->assertArrayHasKey('framework/schedule-ok-job', $this->inFlight());

        $this->invoke('complete', $task, CheckInStatus::ok(), 1.5);
        $this->assertArrayNotHasKey('framework/schedule-ok-job', $this->inFlight());
        $this->assertArrayHasKey('framework/schedule-ok-job', $this->completed());
    }

    public function test_failed_task_finish_does_not_overwrite_failure(): void
    {
        $task = $this->fakeTask(command: "'php' 'artisan' flaky");

        $this->invoke('starting', $task);
        $this->invoke('complete', $task, CheckInStatus::error(), null); // ScheduledTaskFailed
        $completedAfterFail = $this->completed();

        // The scheduler's finally block then fires ScheduledTaskFinished — must be a no-op.
        $this->invoke('complete', $task, CheckInStatus::ok(), 2.0);

        $this->assertSame($completedAfterFail, $this->completed());
        $this->assertArrayHasKey('framework/schedule-flaky', $this->completed());
    }

    private function fakeTask(string $command = '', ?string $description = null, string $expression = '', mixed $timezone = null): object
    {
        return new class($command, $description, $expression, $timezone)
        {
            public function __construct(
                public string $command,
                public ?string $description,
                public string $expression,
                public mixed $timezone,
            ) {}

            public function mutexName(): string
            {
                $name = $this->description !== null && $this->description !== ''
                    ? $this->description
                    : ($this->command !== '' ? $this->command : 'bare');

                if (preg_match("/artisan'?\s+'?([^'\s]+)/", $name, $m) === 1) {
                    $name = $m[1];
                }

                return 'framework/schedule-'.strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
            }
        };
    }

    private function slugFor(object $task): ?string
    {
        $method = new ReflectionMethod(ScheduleMonitoringInstrumentation::class, 'slugFor');

        return $method->invoke(null, $task);
    }

    private function configFor(object $task): ?MonitorConfig
    {
        $method = new ReflectionMethod(ScheduleMonitoringInstrumentation::class, 'configFor');

        return $method->invoke(null, $task);
    }

    private function invoke(string $name, mixed ...$args): void
    {
        $method = new ReflectionMethod(ScheduleMonitoringInstrumentation::class, $name);
        $method->invoke(null, ...$args);
    }

    /** @return array<string, string> */
    private function inFlight(): array
    {
        $prop = new ReflectionProperty(ScheduleMonitoringInstrumentation::class, 'inFlight');

        return $prop->getValue();
    }

    /** @return array<string, true> */
    private function completed(): array
    {
        $prop = new ReflectionProperty(ScheduleMonitoringInstrumentation::class, 'completed');

        return $prop->getValue();
    }
}
