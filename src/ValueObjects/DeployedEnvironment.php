<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/**
 * One deployed copy of a project, and the directory it came from.
 */
readonly class DeployedEnvironment
{
    public function __construct(
        public string $project,
        public string $environment,
        public string $namespace,
        public string $worktree,
        public bool $present,
    ) {}

    /** What this copy is called on the cluster. */
    public function name(): string
    {
        return $this->environment === '' ? $this->project : $this->project.'-'.$this->environment;
    }

    /**
     * Whether this is an environment whose worktree has gone.
     *
     * THE DEFAULT ENVIRONMENT IS NEVER ORPHANED, however missing its directory
     * is. A project checked out at `~/Projects/shop` and moved to `~/code/shop`
     * has not been abandoned, and deleting its database because a path changed
     * would be the worst thing this tool could do.
     */
    public function orphaned(): bool
    {
        return $this->environment !== '' && ! $this->present;
    }
}
