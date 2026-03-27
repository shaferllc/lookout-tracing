<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * In-memory breadcrumbs attached to the next error report (Laravel / worker lifecycle).
 *
 * Cleared at the start of each HTTP route match, console command, and queue job so workers do not leak context across units of work.
 */
final class BreadcrumbBuffer
{
    /** @var list<array{type?: string, category?: string, message?: string, level?: string, timestamp?: string, data?: array<string, mixed>}> */
    private static array $items = [];

    private static int $maxItems = 50;

    public static function configureMaxItems(int $max): void
    {
        self::$maxItems = max(1, min(100, $max));
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function add(
        string $type,
        string $message,
        string $level = 'info',
        ?array $data = null,
        ?string $category = null,
    ): void {
        $row = [
            'type' => substr($type, 0, 64),
            'message' => substr($message, 0, 2000),
            'level' => substr($level, 0, 32),
            'timestamp' => gmdate('c'),
        ];
        if ($category !== null && $category !== '') {
            $row['category'] = substr($category, 0, 128);
        }
        if ($data !== null && $data !== []) {
            $row['data'] = $data;
        }
        self::$items[] = $row;
        if (count(self::$items) > self::$maxItems) {
            self::$items = array_slice(self::$items, -self::$maxItems);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::$items;
    }

    public static function clear(): void
    {
        self::$items = [];
    }
}
