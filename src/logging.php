<?php

declare(strict_types=1);

use Lookout\Tracing\Logging\LogIngestClient;

/**
 * Entry point: {@code lookout_logger()->info('…');} then {@code lookout_logger()->flush();}
 */
function lookout_logger(): LogIngestClient
{
    return LogIngestClient::instance();
}
