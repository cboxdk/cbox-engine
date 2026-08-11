<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Project\ProjectLocator;
use Cbox\Engine\Project\ProjectManifest;
use Illuminate\Console\Command;
use Throwable;

/**
 * The three lines every project command starts with.
 *
 * `--path` because a developer runs commands from wherever they happen to be,
 * and `--env` because the environment is normally the worktree they are standing
 * in — but somebody looking at a colleague's branch, or a script, needs to be
 * able to say which one out loud.
 *
 * @phpstan-require-extends Command
 */
trait LocatesAProject
{
    use Refuses;

    /**
     * The project here, or null with the reason already printed.
     */
    protected function locateProject(ProjectLocator $locator): ?ProjectManifest
    {
        $path = $this->option('path');
        $from = is_string($path) && $path !== '' ? $path : (getcwd() ?: '.');

        // Absent and empty are different answers: no `--env` means "whatever
        // this directory is", and `--env=` means "the default one, whatever
        // directory I am in".
        $named = $this->hasOption('env') ? $this->option('env') : null;
        $environment = is_string($named) ? $named : null;

        try {
            return $locator->locate($from, $environment);
        } catch (Throwable $e) {
            // The locator's and the reader's own sentences. They name the field
            // and say what to do, which is more than this could add — and under
            // `--json` they arrive as a document rather than as prose.
            $this->refuse($e->getMessage());

            return null;
        }
    }

    /**
     * How this project should be referred to in output.
     *
     * The environment is shown, because `demo` and `demo` are the same word for
     * two different databases and somebody has to be able to tell which one they
     * just put to sleep.
     */
    protected function label(ProjectManifest $manifest): string
    {
        return $manifest->environment->isDefault()
            ? $manifest->name
            : $manifest->name.' ('.$manifest->environment->name.')';
    }
}
