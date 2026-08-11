<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * Bring a project back from `cbox sleep`.
 *
 * Its data is where it was: nothing was deleted, the volumes were never
 * detached, and a hibernated Postgres comes back with the database it had.
 */
class WakeCommand extends Command
{
    protected $signature = 'local:wake
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--json : Machine-readable output}';

    protected $description = 'Bring this project back from sleep';

    public function handle(ProjectLocator $locator, ProjectDeployer $deployer): int
    {
        return (new SuspendsAProject)($this, $locator, $deployer, suspended: false);
    }
}
