<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Throwable;

/**
 * Maps stack frames that point at compiled Blade templates
 * (storage/framework/views/&lt;hash&gt;.php) back to the original .blade.php file
 * and line, so the debug page shows template source instead of compiler
 * output.
 *
 * Two steps, both best-effort:
 *  1. The original path comes from the "PATH ... ENDPATH" footer comment
 *     Laravel's compiler appends to every compiled template.
 *  2. The line comes from recompiling the template with a line marker prefixed
 *     to every source line, then scanning backwards from the failing compiled
 *     line for the nearest marker (the Ignition technique). When compilation
 *     isn't possible the frame keeps line 1 — the right file with an
 *     approximate line still beats a hash filename.
 */
final class BladeFrameMapper
{
    private const MARKER = '/*LKLINE:%d*/';

    private const MARKER_PATTERN = '/\/\*LKLINE:(\d+)\*\//';

    /** @var array<string, ?string> memoized compiled-path → blade-path */
    private array $originalPathCache = [];

    /**
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed> the frame, blade-mapped when applicable
     */
    public function map(array $frame): array
    {
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;
        if (! is_string($file) || ! $this->looksCompiled($file)) {
            return $frame;
        }

        $blade = $this->originalPath($file);
        if ($blade === null) {
            return $frame;
        }

        $frame['compiled_file'] = $file;
        $frame['file'] = $blade;
        $frame['is_blade'] = true;

        if (is_numeric($line)) {
            $frame['compiled_line'] = (int) $line;
            $frame['line'] = $this->detectBladeLine($blade, (int) $line) ?? 1;
        }

        return $frame;
    }

    public function looksCompiled(string $file): bool
    {
        return str_contains($file, '/framework/views/') || str_contains($file, '\\framework\\views\\');
    }

    /**
     * Extract the original template path from the compiled file's ENDPATH footer.
     */
    public function originalPath(string $compiledFile): ?string
    {
        if (array_key_exists($compiledFile, $this->originalPathCache)) {
            return $this->originalPathCache[$compiledFile];
        }

        $resolved = null;
        if (is_file($compiledFile) && is_readable($compiledFile)) {
            // The footer is on the last line; reading the tail is enough.
            $size = @filesize($compiledFile);
            $tail = $size !== false && $size > 4096
                ? (string) @file_get_contents($compiledFile, false, null, $size - 4096)
                : (string) @file_get_contents($compiledFile);

            if (preg_match('/\/\*\*PATH\s+(.+?)\s+ENDPATH\*\*\//', $tail, $m) === 1) {
                $candidate = trim($m[1]);
                if ($candidate !== '' && is_file($candidate)) {
                    $resolved = $candidate;
                }
            }
        }

        return $this->originalPathCache[$compiledFile] = $resolved;
    }

    /**
     * Recompile the blade source with per-line markers and find the marker
     * closest above the failing compiled line.
     */
    public function detectBladeLine(string $bladePath, int $compiledLine): ?int
    {
        if ($compiledLine < 1 || ! function_exists('app')) {
            return null;
        }

        try {
            $compiler = app('blade.compiler');
            if (! is_object($compiler) || ! method_exists($compiler, 'compileString')) {
                return null;
            }

            $source = @file_get_contents($bladePath);
            if (! is_string($source) || $source === '') {
                return null;
            }

            $marked = [];
            foreach (explode("\n", $source) as $i => $text) {
                $marked[] = sprintf(self::MARKER, $i + 1).$text;
            }

            $compiled = (string) $compiler->compileString(implode("\n", $marked));
            $compiledLines = explode("\n", $compiled);

            for ($n = min($compiledLine, count($compiledLines)); $n >= 1; $n--) {
                if (preg_match_all(self::MARKER_PATTERN, $compiledLines[$n - 1], $m) > 0) {
                    $markers = array_map('intval', $m[1]);

                    return (int) end($markers);
                }
            }
        } catch (Throwable) {
            // Marker text landed inside an expression and broke compilation,
            // or the compiler isn't bound — fall back to line 1.
        }

        return null;
    }
}
