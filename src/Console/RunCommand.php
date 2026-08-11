<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * Run a command inside this project.
 *
 * THE COMMAND THAT DECIDES WHETHER THIS IS A TOOL OR A WRAPPER. `artisan
 * migrate`, `composer install`, `npm run build`, `tinker` — a developer does
 * these twenty times a day, and a platform that cannot do them is one they keep
 * `kubectl exec -it -n cbox-thing deploy/thing --` beside. Then the platform is
 * a thing to work around.
 *
 * It runs in the RUNNING pod rather than a fresh one, deliberately: the same
 * filesystem, the same bindings, the same environment the application is
 * actually using. A one-off pod would be a different environment answering
 * questions about this one.
 *
 * `--` separates this command's options from the program's:
 *
 *     cbox run -- php artisan migrate --force
 */
class RunCommand extends Command
{
    protected $signature = 'local:run
                            {args* : The program and its arguments}
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--process=web : Which process to run it in}
                            {--no-tty : Do not attach a terminal}';

    protected $description = 'Run a command inside the running project';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes): int
    {
        return (new RunsInsideAProject)($this, $locator, $kubernetes);
    }
}
