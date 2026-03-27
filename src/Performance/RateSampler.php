<?php

declare(strict_types=1);

namespace Lookout\Tracing\Performance;

/**
 * Randomised sampling by rate (0.0–1.0). Default 10% = 0.1.
 */
final class RateSampler implements Sampler
{
    /**
     * @param  array{rate?: float|int|string}  $config
     */
    public function __construct(
        private array $config = [],
    ) {}

    public function shouldSample(array $context): bool
    {
        $rate = (float) ($this->config['rate'] ?? 0.1);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }
}
