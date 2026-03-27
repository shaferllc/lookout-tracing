<?php

declare(strict_types=1);

namespace Lookout\Tracing\Performance;

use Lookout\Tracing\Tracer;

/**
 * Samples if <strong>any</strong> nested sampler accepts (logical OR).
 *
 * Config shape:
 * <code>
 *   'samplers' => [
 *       ['class' => RateSampler::class, 'config' => ['rate' => 0.05]],
 *       ['class' => RateSampler::class, 'config' => ['rate' => 0.2]],
 *   ],
 * </code>
 *
 * Effective hit rate is higher than a single rate (e.g. for layering “baseline + boosted” pools).
 */
final class CompositeOrSampler implements Sampler
{
    /** @var list<Sampler> */
    private array $children = [];

    /**
     * @param  array{samplers?: list<array<string, mixed>>}  $config
     */
    public function __construct(array $config = [])
    {
        $specs = $config['samplers'] ?? [];
        if (! is_array($specs)) {
            return;
        }
        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $this->children[] = Tracer::makeSamplerFromSpec($spec);
        }
    }

    public function shouldSample(array $context): bool
    {
        foreach ($this->children as $child) {
            if ($child->shouldSample($context)) {
                return true;
            }
        }

        return false;
    }
}
