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

    /**
     * RFC 4122 version 4 UUID for `occurrence_uuid` on error ingest and user-feedback correlation.
     */
    public static function occurrenceUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
