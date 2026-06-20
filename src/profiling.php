<?php

declare(strict_types=1);

use Lookout\Tracing\Profiling\ProfileIngestClient;

/**
 * Lookout profiling entry point:
 * {@code lookout_profiles()->time('import.csv', fn () => runImport(), ['rows' => 100]);}
 * {@code lookout_profiles()->start('checkout.submit', ['step' => 'payment'])->stop(['ok' => true]);}
 * {@code lookout_profiles()->profile('aggregate')->sendAggregate([...]);}
 */
function lookout_profiles(): ProfileIngestClient
{
    return ProfileIngestClient::instance();
}
