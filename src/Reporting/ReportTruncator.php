<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting;

/**
 * Truncates ingest payloads to stay within Lookout API limits (client-side trimmer).
 */
final class ReportTruncator
{
    /**
     * @param  array{
     *     max_message_length?: int,
     *     max_stack_trace_bytes?: int,
     *     max_stack_frames?: int,
     *     max_stack_frame_args_json?: int,
     *     max_breadcrumbs?: int,
     *     max_breadcrumb_message?: int,
     *     max_breadcrumb_data_json?: int,
     *     max_context_json?: int,
     * }  $limits
     */
    public function __construct(
        private array $limits = [],
    ) {
        $this->limits = array_merge([
            'max_message_length' => 131_072,
            'max_stack_trace_bytes' => 524_288,
            'max_stack_frames' => 200,
            'max_stack_frame_args_json' => 4096,
            'max_breadcrumbs' => 50,
            'max_breadcrumb_message' => 2000,
            'max_breadcrumb_data_json' => 8192,
            'max_context_json' => 262_144,
        ], $this->limits);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function trim(array $payload): array
    {
        $maxMsg = (int) $this->limits['max_message_length'];
        if (isset($payload['message']) && is_string($payload['message'])) {
            $payload['message'] = $this->truncateUtf8($payload['message'], $maxMsg);
        }

        $maxStack = (int) $this->limits['max_stack_trace_bytes'];
        if (isset($payload['stack_trace']) && is_string($payload['stack_trace'])) {
            $payload['stack_trace'] = $this->truncateUtf8($payload['stack_trace'], $maxStack);
        }

        $maxFrames = (int) $this->limits['max_stack_frames'];
        $maxFrameArgs = (int) ($this->limits['max_stack_frame_args_json'] ?? 4096);
        if (isset($payload['stack_frames']) && is_array($payload['stack_frames'])) {
            $frames = array_slice($payload['stack_frames'], 0, $maxFrames);
            foreach ($frames as &$frame) {
                if (! is_array($frame) || ! isset($frame['args'])) {
                    continue;
                }
                $enc = json_encode($frame['args']);
                if (is_string($enc) && strlen($enc) > $maxFrameArgs) {
                    $frame['args'] = ['_truncated' => true, '_bytes' => strlen($enc)];
                }
            }
            unset($frame);
            $payload['stack_frames'] = $frames;
        }

        $maxCrumbs = (int) $this->limits['max_breadcrumbs'];
        $maxCrumbMsg = (int) $this->limits['max_breadcrumb_message'];
        $maxData = (int) $this->limits['max_breadcrumb_data_json'];
        if (isset($payload['breadcrumbs']) && is_array($payload['breadcrumbs'])) {
            $crumbs = array_slice($payload['breadcrumbs'], 0, $maxCrumbs);
            $out = [];
            foreach ($crumbs as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $row = $c;
                if (isset($row['message']) && is_string($row['message'])) {
                    $row['message'] = $this->truncateUtf8($row['message'], $maxCrumbMsg);
                }
                if (isset($row['data']) && is_array($row['data'])) {
                    $enc = json_encode($row['data']);
                    if (is_string($enc) && strlen($enc) > $maxData) {
                        $row['data'] = ['_truncated' => true, '_bytes' => strlen($enc)];
                    }
                }
                $out[] = $row;
            }
            $payload['breadcrumbs'] = $out;
        }

        $maxCtx = (int) $this->limits['max_context_json'];
        if (isset($payload['context']) && is_array($payload['context'])) {
            $payload['context'] = $this->trimContext($payload['context'], $maxCtx);
        }

        if (isset($payload['solution']) && is_string($payload['solution'])) {
            $payload['solution'] = $this->truncateUtf8($payload['solution'], 16_000);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function trimContext(array $ctx, int $maxJsonBytes): array
    {
        $encoded = json_encode($ctx);
        if (! is_string($encoded) || strlen($encoded) <= $maxJsonBytes) {
            return $ctx;
        }

        return ['_truncated' => true, '_original_context_bytes' => strlen($encoded)];
    }

    private function truncateUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes);
    }
}
