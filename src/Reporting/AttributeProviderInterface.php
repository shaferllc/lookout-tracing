<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

/**
 * Supplies scalar (or null) attributes merged into {@code context.attributes} on the ingest payload.
 *
 * @return array<string, string|int|float|bool|null>
 */
interface AttributeProviderInterface
{
    public function attributes(): array;
}
