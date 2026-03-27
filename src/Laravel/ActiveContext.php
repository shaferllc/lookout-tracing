<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

/**
 * Holds the current queue job class, Artisan command name, and HTTP route for error context.
 */
final class ActiveContext
{
    private static ?string $queueJob = null;

    private static ?string $consoleCommand = null;

    private static ?string $httpRoute = null;

    public static function setQueueJob(?string $name): void
    {
        self::$queueJob = $name;
    }

    public static function setConsoleCommand(?string $name): void
    {
        self::$consoleCommand = $name;
    }

    public static function setHttpRoute(?string $route): void
    {
        self::$httpRoute = $route;
    }

    public static function queueJob(): ?string
    {
        return self::$queueJob;
    }

    public static function consoleCommand(): ?string
    {
        return self::$consoleCommand;
    }

    public static function httpRoute(): ?string
    {
        return self::$httpRoute;
    }

    public static function reset(): void
    {
        self::$queueJob = null;
        self::$consoleCommand = null;
        self::$httpRoute = null;
    }
}
