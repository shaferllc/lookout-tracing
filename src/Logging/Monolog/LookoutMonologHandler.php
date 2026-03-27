<?php

declare(strict_types=1);

namespace Lookout\Tracing\Logging\Monolog;

use Lookout\Tracing\Logging\LogIngestClient;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Push Monolog records into {@see LogIngestClient} (enable logging in {@code lookout-tracing} config first).
 */
final class LookoutMonologHandler extends AbstractProcessingHandler
{
    private readonly LogIngestClient $client;

    public function __construct(
        ?LogIngestClient $client = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        $this->client = $client ?? LogIngestClient::instance();
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->client->isEnabled()) {
            return;
        }

        $level = strtolower($record->level->getName());
        if ($level === 'notice') {
            $level = 'info';
        }
        if ($level === 'critical' || $level === 'alert' || $level === 'emergency') {
            $level = 'fatal';
        }

        $attributes = [];
        foreach ($record->context as $k => $v) {
            $key = (string) $k;
            if ($key === '') {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $attributes[$key] = $v;
            } else {
                try {
                    $attributes[$key] = json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (\JsonException) {
                    $attributes[$key] = null;
                }
            }
        }
        foreach ($record->extra as $k => $v) {
            $key = 'extra.'.(string) $k;
            if (is_scalar($v) || $v === null) {
                $attributes[$key] = $v;
            } else {
                try {
                    $attributes[$key] = json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (\JsonException) {
                    $attributes[$key] = null;
                }
            }
        }

        $row = [
            'level' => $level,
            'message' => $record->message,
            'source' => 'php.monolog',
            'logger' => $record->channel,
            'timestamp' => $record->datetime->getTimestamp() + ((int) $record->datetime->format('u')) / 1_000_000,
        ];
        if ($attributes !== []) {
            $row['attributes'] = $attributes;
        }

        $this->client->enqueueEntry($row);
    }
}
