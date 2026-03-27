<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Portable process / OS / PHP runtime fields for error ingest {@code context.server}.
 */
final class ServerRuntimeSnapshot
{
    /**
     * @return array<string, string|int>
     */
    public static function collect(): array
    {
        $out = [];

        if (function_exists('gethostname')) {
            $host = @gethostname();
            if (is_string($host) && $host !== '') {
                $out['hostname'] = substr($host, 0, 256);
            }
        }

        if (function_exists('php_sapi_name')) {
            $sapi = php_sapi_name();
            if (is_string($sapi) && $sapi !== '') {
                $out['php_sapi'] = substr($sapi, 0, 64);
            }
        }

        if (function_exists('php_uname')) {
            $sys = @php_uname('s');
            $rel = @php_uname('r');
            if (is_string($sys) && $sys !== '') {
                $out['os'] = $rel !== false && is_string($rel) && $rel !== ''
                    ? substr($sys.' '.$rel, 0, 128)
                    : substr($sys, 0, 128);
            }
            $machine = @php_uname('m');
            if (is_string($machine) && $machine !== '') {
                $out['machine'] = substr($machine, 0, 64);
            }
        }

        if (function_exists('getmypid')) {
            $pid = getmypid();
            if (is_int($pid) && $pid > 0) {
                $out['pid'] = $pid;
            }
        }

        $mem = ini_get('memory_limit');
        if (is_string($mem) && $mem !== '') {
            $out['memory_limit'] = substr($mem, 0, 32);
        }

        $met = ini_get('max_execution_time');
        if ($met !== false && $met !== '') {
            $out['max_execution_time'] = substr((string) $met, 0, 32);
        }

        $tz = @date_default_timezone_get();
        if (is_string($tz) && $tz !== '') {
            $out['timezone'] = substr($tz, 0, 64);
        }

        return $out;
    }
}
