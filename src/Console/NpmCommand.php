<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * `npm …`, inside the project.
 *
 * Node 22 ships in the standard tier of the Cbox base images, so a frontend
 * build runs in the same container the application does — same versions, and
 * `node_modules` built for the architecture that will run it rather than the
 * one the developer's laptop happens to be.
 */
class NpmCommand extends Command
{
    protected $signature = 'local:npm
                            {args* : The npm command and its arguments}
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--no-tty : Do not attach a terminal}';

    protected $description = 'Run npm inside the running project';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes): int
    {
        return (new RunsInsideAProject)($this, $locator, $kubernetes, ['npm']);
    }
}
