<?php

declare(strict_types=1);

namespace Lookout\Tracing;

final class Id
{
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
