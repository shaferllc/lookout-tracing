<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Illuminate\Contracts\Foundation\Application;
use Lookout\Tracing\Dump\DumpIngestClient;
use Lookout\Tracing\Reporting\ErrorReportClient;
use Lookout\Tracing\ThrowableSupport;
use Lookout\Tracing\Tracer;
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
 *
 * Meta keys the page understands (all optional):
 *  - request_details — RequestDetailsCollector output for the live request
 *  - lookout_url     — explicit "View in Lookout" link (overrides derivation)
 *  - reference       — support/reference id shown in the header
 *  - privileged      — viewer passed the production gate
 */
final class DebugPageRenderer
{
    public function __construct(
        private readonly FrameSourceResolver $source = new FrameSourceResolver,
        private readonly MarkdownReport $markdown = new MarkdownReport,
        private readonly CurlCommand $curl = new CurlCommand,
        private readonly BladeFrameMapper $blade = new BladeFrameMapper,
        private readonly GitBlame $gitBlame = new GitBlame,
        private readonly OccurrenceStatsClient $stats = new OccurrenceStatsClient,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  view extras (e.g. reference, lookout_url, request_details, privileged)
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

        $meta += $this->liveExtras($payload);

        try {
            (new LocalErrorStore)->save(
                $payload,
                is_array($meta['request_details'] ?? null) ? $meta['request_details'] : [],
            );
        } catch (Throwable) {
            // history is best-effort
        }

        return $this->renderPayload($payload, $meta);
    }

    /**
     * In-process data only available on the live path: dumps still buffered for
     * this request, the trace's finished spans, and dashboard "seen before"
     * stats. Each is best-effort and independently skippable.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function liveExtras(array $payload): array
    {
        $extras = [];

        try {
            $dumps = DumpIngestClient::instance()->bufferedDumps();
            if ($dumps !== []) {
                $extras['dumps'] = $dumps;
            }
        } catch (Throwable) {
            // dumps unavailable
        }

        try {
            $spans = Tracer::instance()->finishedSpanRecords();
            if ($spans !== []) {
                $extras['spans'] = $spans;
            }
        } catch (Throwable) {
            // tracer not initialized
        }

        try {
            $class = $payload['exception_class'] ?? null;
            $message = $payload['message'] ?? null;
            if (is_string($class) && $class !== '') {
                $stats = $this->stats->fetch($class, is_string($message) ? $message : '');
                if ($stats !== null) {
                    $extras['occurrence_stats'] = $stats;
                }
            }
        } catch (Throwable) {
            // stats fetch is strictly optional
        }

        $extras['health'] = HealthChecks::run();

        return $extras;
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
     * resolved, chain flattened, context split into named panels, copy/export
     * strings prebuilt.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function toViewModel(array $payload, array $meta): array
    {
        $editor = EditorLink::fromConfig();

        // The exception's own location can be a compiled Blade file too.
        $primaryLoc = $this->blade->map([
            'file' => $this->str($payload['file'] ?? ''),
            'line' => (int) ($payload['line'] ?? 0),
        ]);

        $exceptions = [];

        // Primary exception.
        $exceptions[] = $this->exceptionView(
            class: $this->str($payload['exception_class'] ?? 'Exception'),
            message: $this->str($payload['message'] ?? ''),
            file: $this->str($primaryLoc['file'] ?? ''),
            line: (int) ($primaryLoc['line'] ?? 0),
            frames: is_array($payload['stack_frames'] ?? null) ? $payload['stack_frames'] : [],
            editor: $editor,
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
                    editor: $editor,
                );
            }
        }

        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $panels = $this->splitContext($context);

        $breadcrumbs = is_array($payload['breadcrumbs'] ?? null) ? $payload['breadcrumbs'] : [];
        $request = is_array($meta['request_details'] ?? null) ? $meta['request_details'] : [];

        $solution = $this->str($payload['solution'] ?? '');
        if ($solution === '') {
            $solution = (string) ErrorSolutions::suggest(
                $this->str($payload['exception_class'] ?? ''),
                $this->str($payload['message'] ?? ''),
            );
        }

        $model = [
            'exceptions' => $exceptions,
            'breadcrumbs' => $breadcrumbs,
            'queries' => $this->queries($breadcrumbs),
            'request' => $request,
            'laravel' => $panels['laravel'],
            'server' => $panels['server'],
            'git' => $panels['git'],
            'log_context' => $panels['log_context'],
            'exception_context' => $panels['exception_context'],
            'context' => $panels['rest'],
            'user' => is_array($payload['user'] ?? null) ? $payload['user'] : null,
            'url' => $this->str($payload['url'] ?? ''),
            'environment' => $this->str($payload['environment'] ?? ($panels['laravel']['application_env'] ?? '')),
            'release' => $this->str($payload['release'] ?? ''),
            'commit_sha' => $this->str($payload['commit_sha'] ?? ''),
            'level' => $this->str($payload['level'] ?? 'error'),
            'handled' => (bool) ($payload['handled'] ?? false),
            'solution' => $solution,
            'occurrence_uuid' => $this->str($payload['occurrence_uuid'] ?? ''),
            'trace_id' => $this->str($payload['trace_id'] ?? ''),
            'transaction' => $this->str($payload['transaction'] ?? ''),
            'runtime' => $this->runtime($panels['laravel']),
            'lookout_url' => $lookoutUrl = $this->lookoutUrl($payload, $meta),
            // Intent deep links only work through the dashboard's occurrence
            // resolver, not through host-provided custom URLs.
            'share_url' => $lookoutUrl !== null && str_contains($lookoutUrl, '/go/occurrence/') ? $lookoutUrl.'?intent=share' : null,
            'ignore_url' => $lookoutUrl !== null && str_contains($lookoutUrl, '/go/occurrence/') ? $lookoutUrl.'?intent=ignore' : null,
            'dumps' => is_array($meta['dumps'] ?? null) ? $meta['dumps'] : [],
            'timeline' => $this->timeline(is_array($meta['spans'] ?? null) ? $meta['spans'] : []),
            'stats' => is_array($meta['occurrence_stats'] ?? null) ? $meta['occurrence_stats'] : null,
            'health' => is_array($meta['health'] ?? null) ? $meta['health'] : [],
            'action' => $this->runnableAction($payload),
            'meta' => $meta,
        ];

        $model['blame'] = $this->blameFor($exceptions[0]);
        $model['curl'] = $request !== [] ? $this->curl->render($request) : null;
        $model['test_skeleton'] = $request !== []
            ? (new PestTestSkeleton)->render($request, $this->str($payload['exception_class'] ?? 'Exception'))
            : null;
        $model['warnings'] = $this->earlierWarnings($breadcrumbs);
        $model['search_links'] = $this->searchLinks(
            $this->str($payload['exception_class'] ?? ''),
            $this->str($payload['message'] ?? ''),
        );
        $model['editor_id'] = $editor->id();
        $model['markdown'] = $this->markdown->render($model);
        $model['ai_prompt'] = $this->aiPrompt($model['markdown']);
        $model['stack_text'] = $this->str($payload['stack_trace'] ?? '');

        return $model;
    }

    /**
     * Error/warning breadcrumbs that fired BEFORE the crash — often the actual
     * cause. Newest first, capped.
     *
     * @param  list<mixed>  $breadcrumbs
     * @return list<array<string, mixed>>
     */
    private function earlierWarnings(array $breadcrumbs): array
    {
        $warnings = [];
        foreach (array_reverse($breadcrumbs) as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }
            $level = strtolower($this->str($crumb['level'] ?? ''));
            if (in_array($level, ['error', 'warning', 'critical', 'alert', 'emergency'], true)) {
                $warnings[] = $crumb;
            }
            if (count($warnings) >= 10) {
                break;
            }
        }

        return $warnings;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function searchLinks(string $class, string $message): array
    {
        if ($class === '' && $message === '') {
            return [];
        }
        $short = ltrim(strrchr('\\'.$class, '\\') ?: $class, '\\');
        // Strip volatile numbers/ids so searches hit the generic error, not this occurrence.
        $generic = trim((string) preg_replace(['/\b\d+(\.\d+)?\b/', '/\s+/'], [' ', ' '], substr($message, 0, 140)));
        $query = trim($short.' '.$generic.' laravel');

        return [
            ['label' => 'Google', 'url' => 'https://www.google.com/search?q='.urlencode($query)],
            ['label' => 'Stack Overflow', 'url' => 'https://stackoverflow.com/search?q='.urlencode(trim($short.' '.$generic))],
            ['label' => 'Laravel docs', 'url' => 'https://www.google.com/search?q='.urlencode('site:laravel.com/docs '.$short)],
        ];
    }

    /**
     * Blame the first application (non-vendor) frame whose source resolved
     * locally — falling back to the exception's own location.
     *
     * @param  array<string, mixed>  $primary
     * @return array<string, mixed>|null
     */
    private function blameFor(array $primary): ?array
    {
        $candidates = [];
        foreach (is_array($primary['frames'] ?? null) ? $primary['frames'] : [] as $frame) {
            if (! is_array($frame) || ! isset($frame['context_line'])) {
                continue;
            }
            $file = $frame['file'] ?? null;
            if (is_string($file) && ! str_contains($file, '/vendor/') && ! str_contains($file, '\\vendor\\')) {
                $candidates[] = [$file, (int) ($frame['line'] ?? 0)];
                break;
            }
        }
        $file = $this->str($primary['file'] ?? '');
        if ($candidates === [] && $file !== '' && ! str_contains($file, '/vendor/')) {
            $candidates[] = [$file, (int) ($primary['line'] ?? 0)];
        }

        foreach ($candidates as [$candidateFile, $candidateLine]) {
            $blame = $this->gitBlame->forLine($candidateFile, $candidateLine);
            if ($blame !== null) {
                return $blame + ['file' => $candidateFile, 'line' => $candidateLine];
            }
        }

        return null;
    }

    /**
     * Normalize finished span records into waterfall rows (offset/width as % of
     * the captured window), oldest first.
     *
     * @param  list<mixed>  $records
     * @return array{total_ms: float, spans: list<array<string, mixed>>}|null
     */
    private function timeline(array $records): ?array
    {
        $rows = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $start = $record['start_timestamp'] ?? null;
            $end = $record['end_timestamp'] ?? null;
            if (! is_numeric($start) || ! is_numeric($end) || (float) $end < (float) $start) {
                continue;
            }
            $rows[] = [
                'op' => $this->str($record['op'] ?? ''),
                'description' => substr($this->str($record['description'] ?? ''), 0, 160),
                'start' => (float) $start,
                'end' => (float) $end,
                'status' => $this->str($record['status'] ?? ''),
            ];
        }
        if ($rows === []) {
            return null;
        }

        usort($rows, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $rows = array_slice($rows, 0, 80);

        $min = $rows[0]['start'];
        $max = max(array_column($rows, 'end'));
        $total = max($max - $min, 0.000001);

        foreach ($rows as $i => $row) {
            $durationMs = ($row['end'] - $row['start']) * 1000;
            $rows[$i]['duration_ms'] = $durationMs >= 10 ? number_format($durationMs, 0) : number_format($durationMs, 1);
            $rows[$i]['offset_pct'] = number_format(($row['start'] - $min) / $total * 100, 2);
            $rows[$i]['width_pct'] = number_format(max(($row['end'] - $row['start']) / $total * 100, 0.4), 2);
            unset($rows[$i]['start'], $rows[$i]['end']);
        }

        return [
            'total_ms' => (float) number_format($total * 1000, 1, '.', ''),
            'spans' => array_values($rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, command: string, label: string, token: string}|null
     */
    private function runnableAction(array $payload): ?array
    {
        $actionId = RunnableSolutions::match(
            is_string($payload['exception_class'] ?? null) ? $payload['exception_class'] : null,
            is_string($payload['message'] ?? null) ? $payload['message'] : null,
        );

        return $actionId !== null ? RunnableSolutions::describe($actionId) : null;
    }

    private function aiPrompt(string $markdown): string
    {
        return "You are helping debug a Laravel application error. Analyze the report below, identify the most likely root cause, and propose a concrete fix (specific files and code changes). If information is missing, say what you would check next.\n\n---\n\n".$markdown;
    }

    /**
     * Pull the well-known context sub-trees into their own panels; whatever the
     * host app added beyond those stays in the generic Context panel.
     *
     * @param  array<string, mixed>  $context
     * @return array{laravel: array<string, mixed>, server: array<string, mixed>, git: array<string, mixed>, log_context: array<string, mixed>, exception_context: array<string, mixed>, rest: array<string, mixed>}
     */
    private function splitContext(array $context): array
    {
        $take = function (string $key) use (&$context): array {
            $value = is_array($context[$key] ?? null) ? $context[$key] : [];
            unset($context[$key]);

            return $value;
        };

        $panels = [
            'laravel' => $take('laravel'),
            'server' => $take('server'),
            'git' => $take('git'),
            'log_context' => $take('log_context'),
            'exception_context' => $take('exception_context'),
        ];
        $panels['rest'] = $context;

        return $panels;
    }

    /**
     * DB breadcrumbs double as a lightweight query log — surface them as their
     * own panel (oldest first, matching execution order).
     *
     * @param  list<mixed>  $breadcrumbs
     * @return list<array<string, mixed>>
     */
    private function queries(array $breadcrumbs): array
    {
        $queries = [];
        foreach ($breadcrumbs as $crumb) {
            if (is_array($crumb) && ($crumb['category'] ?? null) === 'db') {
                $queries[] = $crumb;
            }
        }

        return $queries;
    }

    /**
     * @param  array<string, mixed>  $laravel
     * @return array<string, string>
     */
    private function runtime(array $laravel): array
    {
        $runtime = [
            'php_version' => PHP_VERSION,
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true)),
            'rendered_at' => date('c'),
        ];

        $fw = $laravel['framework_version'] ?? null;
        if (is_string($fw) && $fw !== '') {
            $runtime['framework_version'] = $fw;
        }

        if (defined('LARAVEL_START') && is_float(LARAVEL_START)) {
            $runtime['elapsed'] = number_format((microtime(true) - LARAVEL_START) * 1000, 0).' ms';
        }

        return $runtime;
    }

    /**
     * "View in Lookout" target. An explicit meta value wins; otherwise, when the
     * SDK is actually configured to report (api key + base URL), deep-link the
     * occurrence via the dashboard's /go/occurrence/{uuid} resolver.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private function lookoutUrl(array $payload, array $meta): ?string
    {
        $explicit = $meta['lookout_url'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $uuid = $payload['occurrence_uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '' || ! function_exists('config')) {
            return null;
        }

        try {
            $base = config('lookout-tracing.base_uri');
            $key = config('lookout-tracing.api_key');
        } catch (Throwable) {
            return null;
        }
        if (! is_string($base) || $base === '' || ! is_string($key) || $key === '') {
            return null;
        }

        return rtrim($base, '/').'/go/occurrence/'.rawurlencode($uuid);
    }

    /**
     * @param  list<mixed>  $frames
     * @return array<string, mixed>
     */
    private function exceptionView(string $class, string $message, string $file, int $line, array $frames, EditorLink $editor): array
    {
        $clean = [];
        foreach ($frames as $frame) {
            if (! is_array($frame)) {
                continue;
            }
            unset($frame['args']); // 7b — never render raw arguments
            $clean[] = $this->blade->map($frame);
        }

        $clean = $this->source->enrich($clean);

        if ($editor->enabled()) {
            foreach ($clean as $i => $frame) {
                $frameFile = $frame['file'] ?? null;
                $frameLine = $frame['line'] ?? null;
                if (is_string($frameFile) && is_numeric($frameLine)) {
                    $href = $editor->href($frameFile, (int) $frameLine);
                    if ($href !== null) {
                        $clean[$i]['editor_href'] = $href;
                        $clean[$i]['editor_path'] = $editor->mappedPath($frameFile);
                    }
                }
            }
        }

        return [
            'class' => $class,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'editor_href' => $file !== '' && $line > 0 ? $editor->href($file, $line) : null,
            'frames' => $clean,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2).' GB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        return number_format($bytes / 1024, 0).' KB';
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
