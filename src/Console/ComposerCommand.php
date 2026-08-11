<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * `composer …`, inside the project.
 *
 * IN THE CONTAINER RATHER THAN ON THE HOST, and that is the whole point: the
 * vendor directory is resolved against the PHP version and extensions the
 * application will actually run on, not against whatever the developer has
 * installed this month. A `composer install` on a Mac with PHP 8.5 producing a
 * lock a container with 8.3 cannot satisfy is the oldest bug in this category.
 */
class ComposerCommand extends Command
{
    protected $signature = 'local:composer
                            {args* : The composer command and its arguments}
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--no-tty : Do not attach a terminal}';

    protected $description = 'Run composer inside the running project';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes): int
    {
        return (new RunsInsideAProject)($this, $locator, $kubernetes, ['composer']);
    }
}
