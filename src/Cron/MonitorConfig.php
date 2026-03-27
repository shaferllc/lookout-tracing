<?php

declare(strict_types=1);

namespace Lookout\Tracing\Cron;

/**
 * Optional monitor upsert fields sent with a check-in (margins, timezone, thresholds).
 */
final class MonitorConfig
{
    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public static function make(
        MonitorSchedule $schedule,
        ?int $checkinMarginMinutes = null,
        ?int $maxRuntimeMinutes = null,
        ?string $timezone = null,
        ?int $failureIssueThreshold = null,
        ?int $recoveryThreshold = null,
        ?string $name = null,
    ): self {
        $data = array_filter([
            'name' => $name,
            'schedule' => $schedule->toArray(),
            'checkin_margin' => $checkinMarginMinutes,
            'max_runtime' => $maxRuntimeMinutes,
            'timezone' => $timezone,
            'failure_issue_threshold' => $failureIssueThreshold,
            'recovery_threshold' => $recoveryThreshold,
        ], fn (mixed $v): bool => $v !== null);

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
