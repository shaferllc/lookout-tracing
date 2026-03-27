<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting\Middleware;

use Lookout\Tracing\Reporting\ReportMiddlewareInterface;

/**
 * Fills {@code solution} from the first non-empty configured hint string (client-supplied text for the event).
 */
final class SolutionsMiddleware implements ReportMiddlewareInterface
{
    /**
     * @param  list<string>  $hints
     */
    public function __construct(
        private array $hints = [],
    ) {}

    public function handle(array $payload): array
    {
        if (isset($payload['solution']) && is_string($payload['solution']) && trim($payload['solution']) !== '') {
            return $payload;
        }

        foreach ($this->hints as $line) {
            if (is_string($line) && trim($line) !== '') {
                $payload['solution'] = trim($line);
                break;
            }
        }

        return $payload;
    }
}
