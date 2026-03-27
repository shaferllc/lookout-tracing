<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Manual highlight notes on the breadcrumb trail for custom operator signals.
 */
final class GlowBreadcrumb
{
    public static function glow(string $message, string $level = 'info', ?array $data = null): void
    {
        $msg = trim($message);
        if ($msg === '') {
            return;
        }
        BreadcrumbBuffer::add('glow', $msg, $level, $data, 'glow');
    }
}
