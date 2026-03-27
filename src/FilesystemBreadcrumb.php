<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Manual filesystem breadcrumbs (there is no universal Laravel hook for all disk I/O).
 */
final class FilesystemBreadcrumb
{
    public static function record(string $operation, string $path, string $level = 'info', ?array $extra = null): void
    {
        $op = trim($operation);
        $p = trim($path);
        if ($op === '' || $p === '') {
            return;
        }
        $data = array_merge(['path' => substr($p, 0, 2048)], $extra ?? []);
        BreadcrumbBuffer::add('filesystem', $op.': '.substr($p, 0, 200), $level, $data, 'fs');
    }
}
