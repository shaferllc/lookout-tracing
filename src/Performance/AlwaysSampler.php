<?php

declare(strict_types=1);

namespace Lookout\Tracing\Performance;

final class AlwaysSampler implements Sampler
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private array $config = [],
    ) {}

    public function shouldSample(array $context): bool
    {
        return true;
    }
}
