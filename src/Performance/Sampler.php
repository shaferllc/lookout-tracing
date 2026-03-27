<?php

declare(strict_types=1);

namespace Lookout\Tracing\Performance;

/**
 * Decides whether a new root trace should be recorded and exported.
 *
 * Incoming {@code sentry-trace} with sampled=0 always disables recording regardless of sampler.
 */
interface Sampler
{
    /**
     * @param  array<string, mixed>  $context  e.g. trace_id, kind (root|child), route, command, job
     */
    public function shouldSample(array $context): bool;
}
