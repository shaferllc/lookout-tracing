<?php

declare(strict_types=1);

use Lookout\Tracing\Metrics\MetricsIngestClient;

/**
 * Lookout metrics entry point: {@code lookout_metrics()->count('orders', 1);} then {@code lookout_metrics()->flush();}
 */
function lookout_metrics(): MetricsIngestClient
{
    return MetricsIngestClient::instance();
}
