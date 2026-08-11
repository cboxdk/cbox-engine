<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * `php artisan …`, inside the project.
 *
 * A SHORTCUT AND NOTHING ELSE, over {@see RunCommand}. It exists because the
 * thing a Laravel developer runs most is not "a command in a container" — it is
 * `artisan migrate`, twenty times a day, and making them write
 * `cbox run -- php artisan migrate` each time is a tax on the most common
 * action there is.
 *
 * The same reasoning would justify `cbox composer` and `cbox npm`; those are
 * here too, for the same reason and by the same route.
 */
class ArtisanCommand extends Command
{
    protected $signature = 'local:artisan
                            {args* : The artisan command and its arguments}
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--no-tty : Do not attach a terminal}';

    protected $description = 'Run an artisan command inside the running project';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes): int
    {
        return (new RunsInsideAProject)(
            $this,
            $locator,
            $kubernetes,
            ['php', 'artisan'],
        );
    }
}
