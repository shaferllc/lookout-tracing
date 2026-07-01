<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Illuminate\Contracts\Foundation\Application;
use Lookout\Tracing\Reporting\ErrorReportClient;
use Lookout\Tracing\ThrowableSupport;
use Throwable;

use function view;

/**
 * Turns a Lookout ingest payload into the interactive debug page HTML — the
 * SDK's own "Ignition". Renders entirely from the in-process payload plus
 * locally-resolved source; it performs no network I/O.
 *
 * Two entry points:
 *  - {@see renderThrowable()} — the live path: build the payload from the
 *    exception (same shape that would be reported) and render it.
 *  - {@see renderPayload()} — the fetch-back path: render a payload already
 *    fetched from Lookout's read API (e.g. opening ?event=<ulid> later).
 *
 * Function arguments are intentionally stripped before rendering: they are the
 * highest-risk / lowest-signal panel, and locally-resolved source snippets give
 * better debugging value.
 */
final class DebugPageRenderer
{
    public function __construct(private readonly FrameSourceResolver $source = new FrameSourceResolver) {}

    /**
     * @param  array<string, mixed>  $meta  view extras (e.g. reference, lookout_url, privileged)
     */
    public function renderThrowable(Throwable $e, ?Application $app = null, array $meta = []): string
    {
        $payload = ErrorReportClient::instance()->buildPayload($e, $app);

        // The ingest payload doesn't carry the "caused by" chain, but the live
        // page has the real Throwable — walk getPrevious() so the page can show
        // the full cause chain. (Fetch-back rendering relies on the payload's
        // own exception_chain instead.)
        if (empty($payload['exception_chain'])) {
            $chain = $this->buildChain($e);
            if ($chain !== []) {
                $payload['exception_chain'] = $chain;
            }
        }

        return $this->renderPayload($payload, $meta);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildChain(Throwable $e): array
    {
        $chain = [];
        $prev = $e->getPrevious();
        $guard = 0;
        while ($prev !== null && $guard < 10) {
            $chain[] = [
                'exception_class' => $prev::class,
                'message' => $prev->getMessage(),
                'file' => $prev->getFile(),
                'line' => $prev->getLine(),
                'stack_frames' => ThrowableSupport::stackFramesFromThrowable($prev),
            ];
            $prev = $prev->getPrevious();
            $guard++;
        }

        return $chain;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    public function renderPayload(array $payload, array $meta = []): string
    {
        return view('lookout-tracing::debug.page', [
            'model' => $this->toViewModel($payload, $meta),
        ])->render();
    }

    /**
     * Shape the payload into exactly what the view needs — args removed, source
     * resolved, chain flattened.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function toViewModel(array $payload, array $meta): array
    {
        $exceptions = [];

        // Primary exception.
        $exceptions[] = $this->exceptionView(
            class: $this->str($payload['exception_class'] ?? 'Exception'),
            message: $this->str($payload['message'] ?? ''),
            file: $this->str($payload['file'] ?? ''),
            line: (int) ($payload['line'] ?? 0),
            frames: is_array($payload['stack_frames'] ?? null) ? $payload['stack_frames'] : [],
        );

        // Previous / nested exceptions.
        if (is_array($payload['exception_chain'] ?? null)) {
            foreach ($payload['exception_chain'] as $prev) {
                if (! is_array($prev)) {
                    continue;
                }
                $exceptions[] = $this->exceptionView(
                    class: $this->str($prev['exception_class'] ?? $prev['class'] ?? 'Exception'),
                    message: $this->str($prev['message'] ?? ''),
                    file: $this->str($prev['file'] ?? ''),
                    line: (int) ($prev['line'] ?? 0),
                    frames: is_array($prev['stack_frames'] ?? null) ? $prev['stack_frames'] : [],
                );
            }
        }

        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        return [
            'exceptions' => $exceptions,
            'breadcrumbs' => is_array($payload['breadcrumbs'] ?? null) ? $payload['breadcrumbs'] : [],
            'context' => $context,
            'user' => is_array($payload['user'] ?? null) ? $payload['user'] : null,
            'url' => $this->str($payload['url'] ?? ''),
            'environment' => $this->str($payload['environment'] ?? ($context['laravel']['env'] ?? '')),
            'release' => $this->str($payload['release'] ?? ''),
            'commit_sha' => $this->str($payload['commit_sha'] ?? ''),
            'meta' => $meta,
        ];
    }

    /**
     * @param  list<mixed>  $frames
     * @return array<string, mixed>
     */
    private function exceptionView(string $class, string $message, string $file, int $line, array $frames): array
    {
        $clean = [];
        foreach ($frames as $frame) {
            if (! is_array($frame)) {
                continue;
            }
            unset($frame['args']); // 7b — never render raw arguments
            $clean[] = $frame;
        }

        $clean = $this->source->enrich($clean);

        return [
            'class' => $class,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'frames' => $clean,
        ];
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
