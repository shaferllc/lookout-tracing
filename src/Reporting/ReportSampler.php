<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

/**
 * Random drop for error reports (client-side sampling). Rate 1.0 = always keep.
 */
final class ReportSampler
{
    public function __construct(
        private float $sampleRate = 1.0,
    ) {
        $this->sampleRate = max(0.0, min(1.0, $this->sampleRate));
    }

    public function shouldKeep(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }
        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $this->sampleRate;
    }
}
