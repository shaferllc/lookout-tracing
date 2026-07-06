<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

/**
 * Generates "open this file:line in your editor" deep links for the local
 * debug page (vscode://, phpstorm://, …), with optional container→host path
 * mapping for Sail/Docker setups where the recorded path
 * (e.g. /var/www/html/app/Foo.php) differs from the path on the machine
 * running the editor.
 */
final class EditorLink
{
    /**
     * URL templates keyed by editor id. {file} is the (mapped) absolute path,
     * {line} the 1-based line number.
     */
    private const TEMPLATES = [
        'vscode' => 'vscode://file/{file}:{line}',
        'vscode-insiders' => 'vscode-insiders://file/{file}:{line}',
        'cursor' => 'cursor://file/{file}:{line}',
        'windsurf' => 'windsurf://file/{file}:{line}',
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'idea' => 'idea://open?file={file}&line={line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
        'zed' => 'zed://file/{file}:{line}',
        'textmate' => 'txmt://open?url=file://{file}&line={line}',
        'nova' => 'nova://open?path={file}&line={line}',
        'emacs' => 'emacs://open?url=file://{file}&line={line}',
        'macvim' => 'mvim://open/?url=file://{file}&line={line}',
    ];

    public function __construct(
        private readonly ?string $editor = null,
        private readonly string $remoteSitesPath = '',
        private readonly string $localSitesPath = '',
    ) {}

    /** Build from `lookout-tracing.debug_page.*` config. */
    public static function fromConfig(): self
    {
        $cfg = [];
        if (function_exists('config')) {
            try {
                $raw = config('lookout-tracing.debug_page');
                if (is_array($raw)) {
                    $cfg = $raw;
                }
            } catch (\Throwable) {
                $cfg = [];
            }
        }

        $editor = $cfg['editor'] ?? null;

        return new self(
            editor: is_string($editor) && $editor !== '' ? strtolower($editor) : null,
            remoteSitesPath: is_string($cfg['remote_sites_path'] ?? null) ? $cfg['remote_sites_path'] : '',
            localSitesPath: is_string($cfg['local_sites_path'] ?? null) ? $cfg['local_sites_path'] : '',
        );
    }

    public function enabled(): bool
    {
        return $this->editor !== null && isset(self::TEMPLATES[$this->editor]);
    }

    public function id(): ?string
    {
        return $this->enabled() ? $this->editor : null;
    }

    /**
     * The recorded path after remote→local mapping — what editor links should
     * point at. Exposed so the page's in-browser editor picker can rebuild
     * hrefs for a different editor without redoing the mapping client-side.
     */
    public function mappedPath(string $file): string
    {
        if ($this->remoteSitesPath !== '' && str_starts_with($file, $this->remoteSitesPath)) {
            return $this->localSitesPath.substr($file, strlen($this->remoteSitesPath));
        }

        return $file;
    }

    /**
     * @return array<string, string> editor id → URL template
     */
    public static function templates(): array
    {
        return self::TEMPLATES;
    }

    public function href(string $file, int $line): ?string
    {
        if (! $this->enabled() || $file === '' || $line < 1) {
            return null;
        }

        $file = $this->mappedPath($file);

        return strtr(self::TEMPLATES[(string) $this->editor], [
            '{file}' => str_replace(['%', '#', '?', ' '], ['%25', '%23', '%3F', '%20'], $file),
            '{line}' => (string) $line,
        ]);
    }
}
