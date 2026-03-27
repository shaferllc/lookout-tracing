<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting\Middleware;

use Lookout\Tracing\Reporting\AttributeProviderInterface;
use Lookout\Tracing\Reporting\ReportMiddlewareInterface;
use Lookout\Tracing\Reporting\ReportScope;

/**
 * Merges {@see ReportScope} and registered {@see AttributeProviderInterface} into {@code context.attributes}.
 */
final class AttributesMiddleware implements ReportMiddlewareInterface
{
    /**
     * @param  iterable<int, AttributeProviderInterface>  $providers
     */
    public function __construct(
        private iterable $providers = [],
    ) {}

    public function handle(array $payload): array
    {
        $attrs = ReportScope::mergedAttributes();
        foreach ($this->providers as $provider) {
            foreach ($provider->attributes() as $k => $v) {
                if (! is_string($k) || $k === '') {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $attrs[$k] = $v;
                }
            }
        }
        if ($attrs === []) {
            return $payload;
        }

        $existing = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $prev = isset($existing['attributes']) && is_array($existing['attributes']) ? $existing['attributes'] : [];
        foreach ($attrs as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $prev[$k] = $v;
            }
        }
        $payload['context'] = array_merge($existing, ['attributes' => $prev]);

        return $payload;
    }
}
