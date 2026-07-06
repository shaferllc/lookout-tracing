<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

/**
 * Generates a paste-ready Pest test that replays the failing request and
 * asserts it no longer 500s — the "Copy failing test" button. Values arrive
 * already redacted from {@see RequestDetailsCollector}, so secrets appear as
 * [REDACTED] placeholders the developer swaps for fixtures.
 */
final class PestTestSkeleton
{
    /**
     * @param  array<string, mixed>  $request  RequestDetailsCollector output
     * @param  string  $exceptionClass  used in the test name
     */
    public function render(array $request, string $exceptionClass): ?string
    {
        $url = isset($request['url']) && is_string($request['url']) ? $request['url'] : '';
        if ($url === '') {
            return null;
        }
        $method = isset($request['method']) && is_string($request['method']) ? strtoupper($request['method']) : 'GET';

        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $short = ltrim(strrchr('\\'.$exceptionClass, '\\') ?: $exceptionClass, '\\');

        $body = is_array($request['body'] ?? null) ? $request['body'] : [];
        $call = match (true) {
            $method === 'GET' => "\$this->get('".addslashes($path)."')",
            $body === [] => '$this->'.strtolower($method)."('".addslashes($path)."')",
            default => '$this->'.strtolower($method)."('".addslashes($path)."', ".$this->exportArray($body, 2).')',
        };

        return implode("\n", [
            "it('handles ".$method.' '.($parts['path'] ?? '/').' without throwing '.$short."', function () {",
            '    // TODO: create the models/state this route needs (factories), and',
            '    // replace any [REDACTED] placeholders with fixture values.',
            '    $response = '.$call.';',
            '',
            '    $response->assertSuccessful();',
            '});',
            '',
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function exportArray(array $data, int $indentLevel): string
    {
        $pad = str_repeat('    ', $indentLevel);
        $inner = str_repeat('    ', $indentLevel + 1);
        $lines = ['['];
        foreach ($data as $key => $value) {
            $exportedKey = is_int($key) ? (string) $key : "'".addslashes((string) $key)."'";
            $exportedValue = match (true) {
                is_array($value) => $this->exportArray($value, $indentLevel + 1),
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_int($value) || is_float($value) => (string) $value,
                default => "'".addslashes((string) $value)."'",
            };
            $lines[] = $inner.$exportedKey.' => '.$exportedValue.',';
        }
        $lines[] = $pad.']';

        return implode("\n", $lines);
    }
}
