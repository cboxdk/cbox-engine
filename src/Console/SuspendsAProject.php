<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectLocator;
use Cbox\Engine\Project\ProjectManifest;
use Illuminate\Console\Command;
use Throwable;

/**
 * The shared half of `cbox sleep` and `cbox wake`.
 *
 * ONE PATH, because the two commands differ by a boolean and nothing else: both
 * read the same manifest, compile the same intent with `suspended` set one way
 * or the other, and apply it. Two copies of this would be two places for the
 * sleeping and waking shapes to stop being each other's inverse.
 */
class SuspendsAProject
{
    public function __invoke(
        Command $command,
        ProjectLocator $locator,
        ProjectDeployer $deployer,
        bool $suspended,
    ): int {
        $option = $command->option('path');
        $from = is_string($option) && $option !== '' ? $option : (getcwd() ?: '.');

        $named = $command->hasOption('env') ? $command->option('env') : null;
        $environment = is_string($named) ? $named : null;

        try {
            $manifest = $locator->locate($from, $environment)->withSuspended($suspended);
        } catch (Throwable $e) {
            $command->getOutput()->error('  '.$e->getMessage());

            return Command::FAILURE;
        }

        $label = $this->label($manifest);

        $outcome = $deployer->deploy($manifest);

        if ($command->option('json')) {
            $command->line((string) json_encode([
                'name' => $manifest->deployedName(),
                'environment' => $manifest->environment->name,
                'suspended' => $suspended,
                'succeeded' => $outcome->succeeded,
                'failure' => $outcome->failure,
            ], JSON_PRETTY_PRINT));

            return $outcome->succeeded ? Command::SUCCESS : Command::FAILURE;
        }

        if (! $outcome->succeeded) {
            $command->getOutput()->error("  {$label} did not change.");
            $command->line("      {$outcome->failure}");

            return Command::FAILURE;
        }

        $command->line($suspended
            ? "  <fg=green>✓</> [{$label}] is asleep. Its data is where it was."
            : "  <fg=green>✓</> [{$label}] is awake.");

        return Command::SUCCESS;
    }

    /** The project's name, and which copy of it, when that is not obvious. */
    private function label(ProjectManifest $manifest): string
    {
        return $manifest->environment->isDefault()
            ? $manifest->name
            : $manifest->name.' ('.$manifest->environment->name.')';
    }
}
