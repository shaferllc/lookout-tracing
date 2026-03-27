<?php

declare(strict_types=1);

namespace Lookout\Tracing\Performance;

/**
 * Enforces max attribute / span-event counts (trace_limits config).
 */
final class TraceLimiter
{
    /**
     * @param  array<string, mixed>  $limits
     */
    public function __construct(
        private array $limits = [],
    ) {}

    public static function defaults(): self
    {
        return new self([
            'max_spans' => 512,
            'max_attributes_per_span' => 128,
            'max_span_events_per_span' => 128,
            'max_attributes_per_span_event' => 128,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function fromConfig(array $overrides): self
    {
        $base = self::defaults()->limits;

        return new self(array_merge($base, array_filter($overrides, static fn ($v) => $v !== null)));
    }

    public function maxSpans(): int
    {
        return max(1, (int) ($this->limits['max_spans'] ?? 512));
    }

    public function maxAttributesPerSpan(): int
    {
        return max(0, (int) ($this->limits['max_attributes_per_span'] ?? 128));
    }

    public function maxSpanEventsPerSpan(): int
    {
        return max(0, (int) ($this->limits['max_span_events_per_span'] ?? 128));
    }

    public function maxAttributesPerSpanEvent(): int
    {
        return max(0, (int) ($this->limits['max_attributes_per_span_event'] ?? 128));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function trimSpanData(array $data): array
    {
        return $this->trimTopLevelKeys($data, $this->maxAttributesPerSpan());
    }

    /**
     * Trims top-level keys only (nested arrays are untouched).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function trimTopLevelKeys(array $data, int $max): array
    {
        if ($max <= 0) {
            return [];
        }
        if (count($data) <= $max) {
            return $data;
        }

        return array_slice($data, 0, $max, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function trimEventAttributes(array $attributes): array
    {
        $max = $this->maxAttributesPerSpanEvent();
        if ($max === 0) {
            return [];
        }
        if (count($attributes) <= $max) {
            return $attributes;
        }

        return array_slice($attributes, 0, $max, true);
    }
}
