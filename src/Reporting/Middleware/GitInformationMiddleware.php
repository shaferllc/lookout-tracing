<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting\Middleware;

use Lookout\Tracing\Reporting\ReportMiddlewareInterface;

/**
 * Adds {@code context.git} via short-lived {@code git} CLI calls (best-effort).
 */
final class GitInformationMiddleware implements ReportMiddlewareInterface
{
    public function __construct(
        private ?string $workingDirectory = null,
    ) {}

    public function handle(array $payload): array
    {
        $cwd = $this->workingDirectory;
        if ($cwd === null || $cwd === '' || ! is_dir($cwd)) {
            if (function_exists('base_path')) {
                try {
                    $cwd = base_path();
                } catch (\Throwable) {
                    return $payload;
                }
            }
        }
        if ($cwd === '' || ! is_dir($cwd)) {
            return $payload;
        }

        $commit = $this->runGit($cwd, ['log', '-1', '--format=%H']);
        $branch = $this->runGit($cwd, ['rev-parse', '--abbrev-ref', 'HEAD']);

        $git = [];
        if ($commit !== null) {
            $git['commit'] = $commit;
        }
        if ($branch !== null && $branch !== 'HEAD') {
            $git['branch'] = $branch;
        }
        if ($git === []) {
            return $payload;
        }

        $existing = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $prev = isset($existing['git']) && is_array($existing['git']) ? $existing['git'] : [];
        $payload['context'] = array_merge($existing, ['git' => array_merge($prev, $git)]);

        return $payload;
    }

    /**
     * @param  list<string>  $args
     */
    private function runGit(string $cwd, array $args): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }
        $cmd = array_merge(['git'], $args);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, $cwd);
        if (! is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 0, 200_000);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if (! is_string($out)) {
            return null;
        }
        $s = trim($out);

        return $s === '' ? null : substr($s, 0, 256);
    }
}
