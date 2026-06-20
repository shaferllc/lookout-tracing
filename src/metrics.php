<?php

declare(strict_types=1);

use Lookout\Tracing\Metrics\MetricsIngestClient;

/**
 * Lookout metrics entry point:
 * {@code lookout_metrics()->count('orders', 1);}
 * {@code lookout_metrics()->start('checkout')->stop(['ok' => true]);}
 * {@code lookout_metrics()->metric('latency')->attributes([...])->distribution(42.0);}
 */
function lookout_metrics(): MetricsIngestClient
{
    return MetricsIngestClient::instance();
}
