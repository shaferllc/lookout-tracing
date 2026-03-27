<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * Wraps code with **xhprof** or **Tideways XHProf** (same hierarchical output shape).
 *
 * Tideways is common in production; xhprof is the original PECL extension.
 */
final class XhprofLikeExporter
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{0: T, 1: array<string, mixed>}
     */
    public static function profileCall(string $agent, callable $callback): array
    {
        if ($agent === 'tideways') {
            if (! function_exists('tideways_xhprof_enable') || ! function_exists('tideways_xhprof_disable')) {
                throw new \RuntimeException('Tideways xhprof functions are not available (install tideways extension).');
            }
            $twFlags = (defined('TIDEWAYS_FLAGS_CPU') && defined('TIDEWAYS_FLAGS_MEMORY'))
                ? (TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY)
                : 0;
            tideways_xhprof_enable($twFlags);
            try {
                $result = $callback();
            } finally {
                $data = tideways_xhprof_disable();
            }

            return [$result, is_array($data) ? $data : []];
        }

        if (! function_exists('xhprof_enable') || ! function_exists('xhprof_disable')) {
            throw new \RuntimeException('xhprof extension is not available (pecl install xhprof).');
        }
        if (defined('XHPROF_FLAGS_CPU') && defined('XHPROF_FLAGS_MEMORY')) {
            xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
        } else {
            xhprof_enable();
        }
        try {
            $result = $callback();
        } finally {
            $data = xhprof_disable();
        }

        return [$result, is_array($data) ? $data : []];
    }

    /**
     * @param  array<string, mixed>  $xhprofTree  Raw output from xhprof / tideways_xhprof_disable().
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function toIngestPayload(string $agent, array $xhprofTree, array $context = []): array
    {
        $agent = $agent === 'tideways' ? 'tideways' : 'xhprof';

        return array_filter(array_merge([
            'agent' => $agent,
            'format' => 'xhprof.callgraph.v1',
            'data' => $xhprofTree,
        ], $context), fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
