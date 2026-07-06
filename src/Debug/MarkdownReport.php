<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

/**
 * Renders the debug-page view model as a Markdown report — what the "Copy as
 * Markdown" button puts on the clipboard. Built server-side so the page's JS
 * stays a one-line clipboard call and the output is unit-testable.
 *
 * The report is meant to be pasted into an issue tracker or an AI assistant:
 * headline, environment table, stack trace with the top source snippet,
 * request summary, queries, breadcrumbs, and app/server context.
 */
final class MarkdownReport
{
    private const MAX_FRAMES = 40;

    private const MAX_BREADCRUMBS = 30;

    /**
     * @param  array<string, mixed>  $model  the DebugPageRenderer view model
     */
    public function render(array $model): string
    {
        $lines = [];
        $exceptions = is_array($model['exceptions'] ?? null) ? $model['exceptions'] : [];
        $primary = is_array($exceptions[0] ?? null) ? $exceptions[0] : [];

        $class = $this->str($primary['class'] ?? 'Exception');
        $message = $this->str($primary['message'] ?? '');

        $lines[] = '# '.$class.($message !== '' ? ': '.$message : '');
        $lines[] = '';

        foreach ($this->factRows($model, $primary) as [$label, $value]) {
            $lines[] = '- **'.$label.':** '.$value;
        }
        $lines[] = '';

        foreach ($exceptions as $i => $ex) {
            if (! is_array($ex)) {
                continue;
            }
            if ($i === 0) {
                $lines[] = '## Stack trace';
            } else {
                $lines[] = '## Caused by: '.$this->str($ex['class'] ?? 'Exception')
                    .($this->str($ex['message'] ?? '') !== '' ? ' — '.$this->str($ex['message']) : '');
            }
            $lines[] = '';
            $lines[] = '```';
            array_push($lines, ...$this->stackLines($ex));
            $lines[] = '```';
            $lines[] = '';

            if ($i === 0) {
                array_push($lines, ...$this->sourceSnippet($ex));
            }
        }

        array_push($lines, ...$this->requestSection($model));
        array_push($lines, ...$this->querySection($model));
        array_push($lines, ...$this->breadcrumbSection($model));
        array_push($lines, ...$this->kvSection('Application', is_array($model['laravel'] ?? null) ? $model['laravel'] : []));
        array_push($lines, ...$this->kvSection('Server', is_array($model['server'] ?? null) ? $model['server'] : []));
        array_push($lines, ...$this->kvSection('Git', is_array($model['git'] ?? null) ? $model['git'] : []));
        array_push($lines, ...$this->jsonSection('Exception context', is_array($model['exception_context'] ?? null) ? $model['exception_context'] : []));
        array_push($lines, ...$this->jsonSection('Log context', is_array($model['log_context'] ?? null) ? $model['log_context'] : []));
        array_push($lines, ...$this->jsonSection('Context', is_array($model['context'] ?? null) ? $model['context'] : []));

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $primary
     * @return list<array{0: string, 1: string}>
     */
    private function factRows(array $model, array $primary): array
    {
        $rows = [];
        $push = function (string $label, mixed $value) use (&$rows): void {
            $s = $this->str($value);
            if ($s !== '') {
                $rows[] = [$label, $s];
            }
        };

        $push('URL', $model['url'] ?? '');
        $push('Environment', $model['environment'] ?? '');
        $file = $this->str($primary['file'] ?? '');
        if ($file !== '') {
            $push('Location', $file.':'.$this->str($primary['line'] ?? ''));
        }
        $push('Occurrence', $model['occurrence_uuid'] ?? '');
        $push('Trace', $model['trace_id'] ?? '');
        $push('Transaction', $model['transaction'] ?? '');
        $push('Release', $model['release'] ?? '');
        $push('Commit', $model['commit_sha'] ?? '');

        $runtime = is_array($model['runtime'] ?? null) ? $model['runtime'] : [];
        $push('PHP', $runtime['php_version'] ?? '');
        $push('Laravel', $runtime['framework_version'] ?? '');
        $push('Peak memory', $runtime['peak_memory'] ?? '');
        $push('Captured', $runtime['rendered_at'] ?? '');

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $ex
     * @return list<string>
     */
    private function stackLines(array $ex): array
    {
        $frames = is_array($ex['frames'] ?? null) ? $ex['frames'] : [];
        $out = [];
        $total = count($frames);
        foreach ($frames as $i => $frame) {
            if (! is_array($frame)) {
                continue;
            }
            if ($i >= self::MAX_FRAMES) {
                $out[] = sprintf('… %d more frames', $total - self::MAX_FRAMES);
                break;
            }
            $fn = $this->str($frame['class'] ?? '').$this->str($frame['type'] ?? '').$this->str($frame['function'] ?? '');
            $loc = $this->str($frame['file'] ?? '[internal]');
            $line = $this->str($frame['line'] ?? '');
            $out[] = sprintf('#%d %s%s  %s', $i, $loc, $line !== '' ? ':'.$line : '', $fn !== '' ? $fn.'()' : '{main}');
        }
        if ($out === []) {
            $out[] = '(no stack frames)';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ex
     * @return list<string>
     */
    private function sourceSnippet(array $ex): array
    {
        $frames = is_array($ex['frames'] ?? null) ? $ex['frames'] : [];
        foreach ($frames as $frame) {
            if (! is_array($frame) || ! isset($frame['context_line'])) {
                continue;
            }
            $start = (int) ($frame['context_start_line'] ?? 1);
            $lines = ['## Source ('.$this->str($frame['file'] ?? '').')', '', '```php'];
            $n = $start;
            foreach (array_merge(
                is_array($frame['pre_context'] ?? null) ? $frame['pre_context'] : [],
                [$frame['context_line']],
                is_array($frame['post_context'] ?? null) ? $frame['post_context'] : [],
            ) as $idx => $text) {
                $isTarget = $n === $start + count(is_array($frame['pre_context'] ?? null) ? $frame['pre_context'] : []);
                $lines[] = sprintf('%s%5d  %s', $isTarget ? '>' : ' ', $n, $this->str($text));
                $n++;
            }
            $lines[] = '```';
            $lines[] = '';

            return $lines;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $model
     * @return list<string>
     */
    private function requestSection(array $model): array
    {
        $req = is_array($model['request'] ?? null) ? $model['request'] : [];
        if ($req === []) {
            return [];
        }

        $lines = ['## Request', ''];
        $method = $this->str($req['method'] ?? '');
        $url = $this->str($req['url'] ?? '');
        if ($method !== '' || $url !== '') {
            $lines[] = '`'.trim($method.' '.$url).'`';
            $lines[] = '';
        }

        $route = is_array($req['route'] ?? null) ? $req['route'] : [];
        if ($route !== []) {
            $bits = array_filter([
                isset($route['name']) ? 'name `'.$this->str($route['name']).'`' : null,
                isset($route['action']) ? 'action `'.$this->str($route['action']).'`' : null,
            ]);
            if ($bits !== []) {
                $lines[] = 'Route: '.implode(' · ', $bits);
                $lines[] = '';
            }
        }

        foreach (['headers' => 'Headers', 'query' => 'Query', 'body' => 'Body', 'session' => 'Session'] as $key => $title) {
            $data = is_array($req[$key] ?? null) ? $req[$key] : [];
            if ($data === []) {
                continue;
            }
            $lines[] = '**'.$title.'**';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $this->json($data);
            $lines[] = '```';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return list<string>
     */
    private function querySection(array $model): array
    {
        $queries = is_array($model['queries'] ?? null) ? $model['queries'] : [];
        if ($queries === []) {
            return [];
        }

        $lines = ['## Queries ('.count($queries).')', '', '```sql'];
        foreach ($queries as $q) {
            if (is_array($q)) {
                $lines[] = $this->str($q['message'] ?? '');
            }
        }
        $lines[] = '```';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return list<string>
     */
    private function breadcrumbSection(array $model): array
    {
        $crumbs = is_array($model['breadcrumbs'] ?? null) ? $model['breadcrumbs'] : [];
        if ($crumbs === []) {
            return [];
        }

        $lines = ['## Breadcrumbs ('.count($crumbs).')', ''];
        $shown = array_slice(array_reverse($crumbs), 0, self::MAX_BREADCRUMBS);
        foreach ($shown as $c) {
            if (! is_array($c)) {
                continue;
            }
            $msg = $c['message'] ?? '';
            $lines[] = sprintf(
                '- `%s` **%s** %s',
                $this->str($c['level'] ?? 'info'),
                $this->str($c['category'] ?? ($c['type'] ?? '')),
                is_string($msg) ? $msg : $this->json(is_array($msg) ? $msg : ['value' => $msg]),
            );
        }
        if (count($crumbs) > self::MAX_BREADCRUMBS) {
            $lines[] = sprintf('- … %d more', count($crumbs) - self::MAX_BREADCRUMBS);
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function kvSection(string $title, array $data): array
    {
        if ($data === []) {
            return [];
        }
        $lines = ['## '.$title, ''];
        foreach ($data as $k => $v) {
            $lines[] = '- **'.$this->str($k).':** '.(is_scalar($v) || $v === null ? $this->str($v) : $this->json(is_array($v) ? $v : []));
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function jsonSection(string $title, array $data): array
    {
        if ($data === []) {
            return [];
        }

        return ['## '.$title, '', '```json', $this->json($data), '```', ''];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function json(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '{}' : $encoded;
    }

    private function str(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
