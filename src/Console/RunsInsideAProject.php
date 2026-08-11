<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * The shared body of `cbox run` and every shortcut over it.
 *
 * ONE PLACE, because the shortcuts differ only in what they put in front of the
 * arguments. Three copies of "find the pod, exec, pass the exit code through"
 * is three chances for one of them to swallow a failure.
 */
class RunsInsideAProject
{
    /**
     * @param  list<string>  $prefix  the program the shortcut stands for
     */
    public function __invoke(Command $command, ProjectLocator $locator, Kubernetes $kubernetes, array $prefix = []): int
    {
        $path = $command->option('path');
        $from = is_string($path) && $path !== '' ? $path : (getcwd() ?: '.');

        $named = $command->hasOption('env') ? $command->option('env') : null;

        try {
            $manifest = $locator->locate($from, is_string($named) ? $named : null);
        } catch (\Throwable $e) {
            $command->getOutput()->error('  '.$e->getMessage());

            return Command::FAILURE;
        }

        /** @var list<string> $arguments */
        $arguments = array_values(array_filter(
            is_array($command->argument('args')) ? $command->argument('args') : [],
            is_string(...),
        ));

        if ($prefix === [] && $arguments === []) {
            $command->getOutput()->error('  Say what to run: cbox run -- php artisan migrate');

            return Command::FAILURE;
        }

        $process = $command->hasOption('process') && is_string($command->option('process'))
            && $command->option('process') !== ''
            ? $command->option('process')
            : 'web';

        $exit = $kubernetes->exec(
            $manifest->namespace(),
            'platform.cbox.dk/service='.$manifest->deployedName().',platform.cbox.dk/process='.$process,
            [...$prefix, ...$arguments],
            tty: ! $command->option('no-tty') && $command->getOutput()->isDecorated(),
            onOutput: static function (string $chunk) use ($command): void {
                $command->getOutput()->write($chunk);
            },
        );

        if ($exit === -1) {
            $label = $manifest->environment->isDefault()
                ? $manifest->name
                : $manifest->name.' ('.$manifest->environment->name.')';

            $command->getOutput()->error("  [{$label}] has no running {$process} process to run that in.");
            $command->line('      `cbox status` says whether it is asleep, idle, or was never deployed.');

            return Command::FAILURE;
        }

        // The program's OWN exit code. A wrapper that returns 0 because it
        // successfully ran something that failed breaks every script it is in.
        return $exit;
    }
}
