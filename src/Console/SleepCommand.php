<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * Put the project away, and keep everything it has.
 *
 * WHAT SCALE-TO-ZERO DOES NOT COVER, measured rather than assumed. A project
 * that scales to zero puts its WEB process away and nothing else: its database,
 * its cache and every worker keep running, because none of them is woken by a
 * request arriving. A sleeping project with a Postgres, a Valkey and one worker
 * still reserved 200m of CPU and 384Mi on this machine. Fifteen of them is three
 * cores before anybody opens a browser.
 *
 * So the automatic part handles the request path and this handles the rest. It
 * suspends everything — the platform already compiles that shape: deployments go
 * to zero replicas, StatefulSets go to zero, and a CloudNativePG cluster
 * hibernates. Every volume stays exactly where it was.
 *
 * The difference from `cbox remove` is the whole point, and the words are chosen
 * to keep them apart: sleeping keeps the data, removing does not.
 */
class SleepCommand extends Command
{
    protected $signature = 'local:sleep
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--json : Machine-readable output}';

    protected $description = 'Put this project away, keeping its data, until you wake it';

    public function handle(ProjectLocator $locator, ProjectDeployer $deployer): int
    {
        return (new SuspendsAProject)($this, $locator, $deployer, suspended: true);
    }
}
