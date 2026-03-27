<?php

declare(strict_types=1);

namespace Lookout\Tracing\Cron;

/**
 * Monitor schedule payload for upserting monitor configuration on check-in.
 *
 * @see https://docs.sentry.io/platforms/php/crons/
 */
final class MonitorSchedule
{
    /**
     * @param  array{type: string, crontab?: string, value?: int, unit?: string}  $data
     */
    private function __construct(private array $data) {}

    public static function crontab(string $expression): self
    {
        return new self([
            'type' => 'crontab',
            'crontab' => $expression,
        ]);
    }

    public static function interval(int $value, string $unit): self
    {
        return new self([
            'type' => 'interval',
            'value' => $value,
            'unit' => $unit,
        ]);
    }

    /**
     * @return array{type: string, crontab?: string, value?: int, unit?: string}
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
