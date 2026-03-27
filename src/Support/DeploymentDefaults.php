<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Best-effort release / commit / deploy time from common platform env vars when not set explicitly.
 */
final class DeploymentDefaults
{
    /**
     * @return array{release: ?string, commit_sha: ?string, deployed_at_unix: ?float}
     */
    public static function fromEnvironment(): array
    {
        $commit = self::firstNonEmptyString([
            getenv('LOOKOUT_COMMIT_SHA') ?: null,
            getenv('SOURCE_VERSION') ?: null,
            getenv('RENDER_GIT_COMMIT') ?: null,
            getenv('VERCEL_GIT_COMMIT_SHA') ?: null,
            getenv('GITHUB_SHA') ?: null,
            getenv('COMMIT_REF') ?: null,
            getenv('K_REVISION') ?: null,
        ]);

        $release = self::firstNonEmptyString([
            getenv('LOOKOUT_RELEASE') ?: null,
            getenv('HEROKU_RELEASE_VERSION') ?: null,
            getenv('RENDER_GIT_BRANCH') ?: null,
            getenv('VERCEL_GIT_COMMIT_REF') ?: null,
            getenv('FLY_RELEASE_ID') ?: null,
        ]);

        if ($release === null && $commit !== null) {
            $release = strlen($commit) > 12 ? substr($commit, 0, 12) : $commit;
        }

        $deployedAt = null;
        $rawDeploy = getenv('LOOKOUT_DEPLOYED_AT');
        if (is_string($rawDeploy) && trim($rawDeploy) !== '') {
            $t = is_numeric($rawDeploy) ? (float) $rawDeploy : (float) strtotime($rawDeploy);
            if ($t > 0) {
                if ($t > 9999999999) {
                    $t /= 1000.0;
                }
                $deployedAt = $t;
            }
        }

        return [
            'release' => $release,
            'commit_sha' => $commit,
            'deployed_at_unix' => $deployedAt,
        ];
    }

    /**
     * @param  list<string|null>  $candidates
     */
    private static function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $s) {
            if (! is_string($s)) {
                continue;
            }
            $t = trim($s);
            if ($t !== '') {
                return $t;
            }
        }

        return null;
    }
}
