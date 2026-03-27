<?php

declare(strict_types=1);

use Lookout\Tracing\Reporting\ErrorReportClient;

/**
 * Occurrence UUID from the last error report built by the Lookout tracing SDK (for user-feedback forms).
 *
 * @see ErrorReportClient::lastOccurrenceUuid()
 */
function lookout_last_error_occurrence_uuid(): ?string
{
    return ErrorReportClient::lastOccurrenceUuid();
}
