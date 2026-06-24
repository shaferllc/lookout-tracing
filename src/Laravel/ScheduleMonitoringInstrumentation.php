<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Lookout\Tracing\Cron\CheckInStatus;
use Lookout\Tracing\Cron\Client as CronClient;
use Lookout\Tracing\Cron\MonitorConfig;
use Lookout\Tracing\Cron\MonitorSchedule;

/**
 * Auto check-ins for Laravel's scheduler: every scheduled task reports in_progress on start and
 * ok/error on finish to {@code POST /api/ingest/cron}, with its cron expression so the server can
 * detect missed runs. Zero user code — no per-task wrapping required.
 *
 * Pairing is process-local (a single {@code schedule:run}); state is static because the scheduler
 * runs tasks within one process. Background tasks ({@code runInBackground}) only get a start +
 * immediate finish in the parent, which is acceptable.
 */
final class ScheduleMonitoringInstrumentation
{
    /** @var array<string, string> task key => in-flight check_in_id ('' when the start ping was not recorded). */
    private static array $inFlight = [];

    /** @var array<string, true> task keys already completed this run (so a failure isn't overwritten by the finally-finish). */
    private static array $completed = [];

    private static ?int $checkinMargin = null;

    public static function register(Dispatcher $events, ?int $checkinMargin = null): void
    {
        if (! class_exists(ScheduledTaskStarting::class) || ! CronClient::isEnabled()) {
            return;
        }

        self::$checkinMargin = $checkinMargin;

        $events->listen(ScheduledTaskStarting::class, static function (ScheduledTaskStarting $event): void {
            self::starting($event->task);
        });
        $events->listen(ScheduledTaskFinished::class, static function (ScheduledTaskFinished $event): void {
            self::complete($event->task, CheckInStatus::ok(), self::runtimeOf($event));
        });
        $events->listen(ScheduledTaskFailed::class, static function (ScheduledTaskFailed $event): void {
            self::complete($event->task, CheckInStatus::error(), null);
        });
    }

    public static function resetForTesting(): void
    {
        self::$inFlight = [];
        self::$completed = [];
        self::$checkinMargin = null;
    }

    private static function starting(mixed $task): void
    {
        $slug = self::slugFor($task);
        if ($slug === null) {
            return;
        }

        $key = self::keyFor($task);
        unset(self::$completed[$key]);
        $id = CronClient::captureCheckIn($slug, CheckInStatus::inProgress(), null, null, self::configFor($task));
        self::$inFlight[$key] = $id ?? '';
    }

    private static function complete(mixed $task, string $status, ?float $runtime): void
    {
        $slug = self::slugFor($task);
        if ($slug === null) {
            return;
        }

        $key = self::keyFor($task);
        // A failed task fires ScheduledTaskFailed then ScheduledTaskFinished; don't let the finish overwrite the failure.
        if (isset(self::$completed[$key])) {
            return;
        }
        self::$completed[$key] = true;

        $id = self::$inFlight[$key] ?? '';
        unset(self::$inFlight[$key]);

        // If we never recorded a start (id empty), send a standalone terminal heartbeat carrying the schedule config.
        CronClient::captureCheckIn($slug, $status, $id !== '' ? $id : null, $runtime, $id === '' ? self::configFor($task) : null);
    }

    private static function runtimeOf(ScheduledTaskFinished $event): ?float
    {
        return property_exists($event, 'runtime') && is_numeric($event->runtime) ? (float) $event->runtime : null;
    }

    private static function configFor(mixed $task): ?MonitorConfig
    {
        $expression = is_object($task) && isset($task->expression) && is_string($task->expression) ? trim($task->expression) : '';
        if ($expression === '') {
            return null;
        }

        return MonitorConfig::make(
            MonitorSchedule::crontab($expression),
            self::$checkinMargin,
            null,
            self::timezoneFor($task),
        );
    }

    private static function timezoneFor(mixed $task): ?string
    {
        $tz = is_object($task) && isset($task->timezone) ? $task->timezone : null;
        if (is_string($tz) && $tz !== '') {
            return $tz;
        }
        if ($tz instanceof \DateTimeZone) {
            return $tz->getName();
        }

        return null;
    }

    /**
     * Stable, human-ish slug for a scheduled task: its description, else the artisan command name,
     * else a hash of the mutex. Returns null only when no identity can be derived.
     */
    private static function slugFor(mixed $task): ?string
    {
        $raw = '';
        if (is_object($task) && isset($task->description) && is_string($task->description) && trim($task->description) !== '') {
            $raw = $task->description;
        } elseif (is_object($task) && isset($task->command) && is_string($task->command) && $task->command !== '') {
            $raw = self::artisanCommandName($task->command);
        } elseif (is_object($task) && method_exists($task, 'getSummaryForDisplay')) {
            $raw = (string) $task->getSummaryForDisplay();
        }

        $slug = self::slugify($raw);
        if ($slug === '') {
            $slug = 'task-'.substr(md5(self::keyFor($task)), 0, 12);
        }

        return $slug;
    }

    private static function keyFor(mixed $task): string
    {
        if ($task instanceof ScheduledEvent && method_exists($task, 'mutexName')) {
            return (string) $task->mutexName();
        }
        if (is_object($task) && method_exists($task, 'mutexName')) {
            return (string) $task->mutexName();
        }
        if (is_object($task) && isset($task->command) && is_string($task->command)) {
            return $task->command;
        }

        return spl_object_hash(is_object($task) ? $task : (object) []);
    }

    private static function artisanCommandName(string $command): string
    {
        if (preg_match("/artisan'?\s+'?([^'\s]+)/", $command, $m) === 1) {
            return $m[1];
        }

        return $command;
    }

    private static function slugify(string $raw): string
    {
        $slug = strtolower($raw);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return substr($slug, 0, 128);
    }
}
