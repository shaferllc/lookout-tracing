<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

/**
 * In-memory queue of ingest payloads flushed on PHP shutdown when queuing is enabled.
 */
final class ReportQueue
{
    /** @var list<array<string, mixed>> */
    private array $items = [];

    private bool $shutdownRegistered = false;

    public function push(array $payload): void
    {
        $this->items[] = $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $out = $this->items;
        $this->items = [];

        return $out;
    }

    public function registerShutdownFlush(callable $flush): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(static function () use ($flush): void {
            try {
                $flush();
            } catch (\Throwable) {
                // Never break shutdown
            }
        });
    }
}
