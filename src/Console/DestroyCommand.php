<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Enums\ClusterPhase;
use Illuminate\Console\Command;

/**
 * Destroy the local cluster and everything in it.
 *
 * ITS OWN COMMAND, and it asks. Everything a developer has put in the cluster
 * goes with it — every database, every volume, every image pulled into it — and
 * rebuilding is minutes. `cbox down` is the one that keeps it all, and the two
 * are deliberately not the same word.
 *
 * `--force` exists for scripts and for agents, which cannot answer a prompt. It
 * has to be typed, which is the point.
 */
class DestroyCommand extends Command
{
    protected $signature = 'local:destroy {--force : Do not ask} {--json : Machine-readable output}';

    protected $description = 'Destroy the local cluster and everything in it';

    public function handle(ClusterManager $cluster): int
    {
        $before = $cluster->state();

        if ($before->phase === ClusterPhase::Absent) {
            $this->line("  Cluster [{$before->name}] does not exist.");

            return self::SUCCESS;
        }

        // A prompt nobody sees is a prompt that did not happen: without a
        // terminal — an agent, a script, CI — this refuses rather than assuming
        // consent it could not ask for.
        if (! $this->option('force') && ! $this->confirmDestruction($before->name)) {
            $this->line('  Left alone.');

            return self::FAILURE;
        }

        $state = $cluster->destroy();

        if ($this->option('json')) {
            $this->line((string) json_encode($state->toArray(), JSON_PRETTY_PRINT));
        } elseif ($state->failure !== '') {
            $this->error("  {$state->failure}");
        } else {
            $this->line("  <fg=green>✓</> Cluster [{$state->name}] destroyed.");
        }

        return $state->failure === '' ? self::SUCCESS : self::FAILURE;
    }

    private function confirmDestruction(string $name): bool
    {
        if (! $this->input->isInteractive()) {
            $this->error('  Refusing to destroy without --force: there is nobody here to ask.');

            return false;
        }

        return $this->confirm(
            "Destroy cluster [{$name}] and everything in it? `cbox down` stops it instead.",
            default: false,
        );
    }
}
