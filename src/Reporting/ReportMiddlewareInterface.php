<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

/**
 * Mutates an error-ingest payload before send (git metadata, attributes, solutions, etc.).
 *
 * @phpstan-type IngestPayload array<string, mixed>
 */
interface ReportMiddlewareInterface
{
    /**
     * @param  IngestPayload  $payload
     * @return IngestPayload
     */
    public function handle(array $payload): array;
}
