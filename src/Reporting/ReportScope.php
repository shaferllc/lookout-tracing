<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

/**
 * Request/job scoped attributes merged into {@code context.attributes}.
 */
final class ReportScope
{
    /** @var list<array<string, string|int|float|bool|null>> */
    private static array $stack = [];

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes
     */
    public static function push(array $attributes): void
    {
        if ($attributes === []) {
            return;
        }
        self::$stack[] = $attributes;
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public static function mergedAttributes(): array
    {
        $out = [];
        foreach (self::$stack as $layer) {
            foreach ($layer as $k => $v) {
                if (! is_string($k) || $k === '') {
                    continue;
                }
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public static function clear(): void
    {
        self::$stack = [];
    }
}
