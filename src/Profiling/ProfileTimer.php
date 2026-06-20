<?php

declare(strict_types=1);

namespace Lookout\Tracing\Profiling;

/**
 * Profiling session started via {@see ProfileIngestClient::start()} or {@see ProfileBuilder::start()}.
 */
final class ProfileTimer
{
    private bool $finished = false;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $contextOverrides
     */
    public function __construct(
        private readonly ProfileIngestClient $client,
        private readonly string $transaction,
        private array $meta,
        private readonly ?ProfileCapture $capture,
        private readonly array $contextOverrides = [],
    ) {}

    /**
     * Stop capture and upload the profile. Returns false when disabled, cancelled, or upload failed.
     *
     * @param  array<string, mixed>  $extraMeta  Merged into profile metadata.
     */
    public function stop(array $extraMeta = []): bool
    {
        if ($this->finished || $this->capture === null) {
            return false;
        }
        $this->finished = true;

        if ($extraMeta !== []) {
            $this->meta = array_merge($this->meta, $extraMeta);
        }

        return $this->client->finishCapture($this->capture, $this->transaction, $this->meta, $this->contextOverrides);
    }

    /**
     * Discard the session without uploading.
     */
    public function cancel(): void
    {
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }
}
