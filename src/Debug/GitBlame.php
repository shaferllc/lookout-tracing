<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Throwable;

/**
 * "Who last touched the crashing line" for the debug page header — a
 * best-effort `git blame -L line,line --porcelain` on the primary app frame.
 * Local render path only; blame data is never added to the ingest payload.
 */
final class GitBlame
{
    /**
     * @return array{sha: string, author: string, summary: string, committed_at: int, age: string}|null
     */
    public function forLine(string $file, int $line): ?array
    {
        if ($line < 1 || $file === '' || ! is_file($file)) {
            return null;
        }

        $cwd = dirname($file);
        $out = $this->runGit($cwd, ['blame', '-L', $line.','.$line, '--porcelain', '--', $file]);
        if ($out === null) {
            return null;
        }

        $parsed = self::parsePorcelain($out);
        if ($parsed === null) {
            return null;
        }

        $parsed['age'] = self::relativeAge($parsed['committed_at']);

        return $parsed;
    }

    /**
     * Parse `git blame --porcelain` output for a single line.
     *
     * @return array{sha: string, author: string, summary: string, committed_at: int, age: string}|null
     */
    public static function parsePorcelain(string $output): ?array
    {
        $lines = explode("\n", $output);
        $header = $lines[0] ?? '';
        if (preg_match('/^([0-9a-f]{40})\s/', $header, $m) !== 1) {
            return null;
        }
        $sha = $m[1];

        // All-zero sha means the line is uncommitted working-tree change.
        if ($sha === str_repeat('0', 40)) {
            return [
                'sha' => '',
                'author' => 'you',
                'summary' => 'Uncommitted local changes',
                'committed_at' => 0,
                'age' => '',
            ];
        }

        $author = '';
        $summary = '';
        $time = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'author ')) {
                $author = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'author-time ')) {
                $time = (int) trim(substr($line, 12));
            } elseif (str_starts_with($line, 'summary ')) {
                $summary = trim(substr($line, 8));
            }
        }

        if ($author === '') {
            return null;
        }

        return [
            'sha' => substr($sha, 0, 10),
            'author' => substr($author, 0, 128),
            'summary' => substr($summary, 0, 200),
            'committed_at' => $time,
            'age' => '',
        ];
    }

    public static function relativeAge(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $delta = max(0, time() - $timestamp);

        return match (true) {
            $delta < 3600 => max(1, intdiv($delta, 60)).' min ago',
            $delta < 86400 => intdiv($delta, 3600).' h ago',
            $delta < 86400 * 30 => intdiv($delta, 86400).' days ago',
            $delta < 86400 * 365 => intdiv($delta, 86400 * 30).' months ago',
            default => intdiv($delta, 86400 * 365).' years ago',
        };
    }

    /**
     * @param  list<string>  $args
     */
    private function runGit(string $cwd, array $args): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        try {
            $cmd = array_merge(['git'], $args);
            $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $spec, $pipes, $cwd);
            if (! is_resource($proc)) {
                return null;
            }
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 0, 200_000);
            $out = stream_get_contents($pipes[1], 8192);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($out)) {
            return null;
        }
        $s = trim($out);

        return $s === '' ? null : $s;
    }
}
