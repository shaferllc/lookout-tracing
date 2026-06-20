<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * Active profiling session (Excimer or cooperative manual pulse).
 */
final class ProfileCapture
{
    public const BACKEND_EXCIMER = 'excimer';

    public const BACKEND_MANUAL = 'manual';

    public function __construct(
        public readonly string $backend,
        public readonly object $handle,
        public readonly float $startedAt,
    ) {}
}
