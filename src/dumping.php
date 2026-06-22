<?php

declare(strict_types=1);

use Lookout\Tracing\Dump\DumpIngestClient;

if (! function_exists('lookout_dump')) {
    /**
     * Explicit, cross-language dump API: capture a value (as a normalized, redacted tree) to the
     * Lookout Dumps watcher. Returns the value so it can be used inline: {@code return lookout_dump($user);}
     *
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    function lookout_dump(mixed $value, ?string $label = null): mixed
    {
        DumpIngestClient::instance()->capture($value, $label);

        return $value;
    }
}
