<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * What the project is saying.
 *
 * THE FIRST THING SOMEBODY REACHES FOR when a deploy did not do what they
 * expected, and a developer tool that cannot show it is a tool they will leave
 * for one that can.
 *
 * Selects by the platform's own labels rather than by pod name, because pod
 * names change on every deploy and the developer is asking about their
 * application, not about a pod. One process by name, or all of them.
 */
class LogsCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:logs
                            {process? : One process by name, or every process when omitted}
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--f|follow : Keep the stream open}
                            {--tail=100 : How many lines of history to start with}';

    protected $description = 'Show what the project in this directory is saying';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes): int
    {
        $manifest = $this->locateProject($locator);

        if ($manifest === null) {
            return self::FAILURE;
        }

        $process = $this->argument('process');
        $selector = 'platform.cbox.dk/service='.$manifest->deployedName();

        if (is_string($process) && $process !== '') {
            $selector .= ',platform.cbox.dk/process='.$process;
        }

        $tail = (int) $this->option('tail');

        $reached = $kubernetes->logs(
            $manifest->namespace(),
            $selector,
            // Written straight through, unbuffered and unformatted. A log line
            // is somebody else's output and rewrapping it would make a stack
            // trace unreadable at exactly the moment it matters.
            fn (string $chunk) => $this->output->write($chunk),
            follow: (bool) $this->option('follow'),
            tail: $tail > 0 ? $tail : 100,
        );

        if (! $reached) {
            $this->error('  Could not read logs for ['.$this->label($manifest).']. Is the cluster up?');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
