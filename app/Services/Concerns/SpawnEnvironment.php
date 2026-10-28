<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Git's repository-local variables, which a git hook exports and every git
 * subprocess (tests, scripts) must drop so it acts on its own directory.
 */
final class SpawnEnvironment
{
    /** Git's repository-local variables, as `git rev-parse --local-env-vars` lists them. */
    public const GIT_REPOSITORY_ENV = [
        'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_CONFIG', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_COUNT',
        'GIT_OBJECT_DIRECTORY', 'GIT_DIR', 'GIT_WORK_TREE', 'GIT_IMPLICIT_WORK_TREE', 'GIT_GRAFT_FILE',
        'GIT_INDEX_FILE', 'GIT_NO_REPLACE_OBJECTS', 'GIT_REPLACE_REF_BASE', 'GIT_PREFIX',
        'GIT_SHALLOW_FILE', 'GIT_COMMON_DIR',
    ];

    /**
     * Env overrides for a git subprocess: each repository variable set to
     * false, which Symfony Process drops even when the parent has it. A CRM
     * started from a git hook (or a test run by one) inherits GIT_DIR, and
     * `git -C <path>` would then act on that repository, not on <path>.
     *
     * @return array<string, false>
     */
    public static function withoutGitRepository(): array
    {
        return array_fill_keys(self::GIT_REPOSITORY_ENV, false);
    }
}
