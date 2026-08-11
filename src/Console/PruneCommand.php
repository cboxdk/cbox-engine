<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Platform\SharedGateway;
use Cbox\Engine\Project\EnvironmentRegistry;
use Cbox\Engine\ValueObjects\DeployedEnvironment;
use Illuminate\Console\Command;

/**
 * Take away the environments whose worktrees are gone.
 *
 * THE OTHER HALF OF "A WORKTREE IS AN ENVIRONMENT". Branching off gives you an
 * environment for free, and the cost of free is that nobody thinks about it
 * again — the branch is merged, the worktree is deleted, and a namespace with a
 * Postgres and a volume stays on this machine forever. Fifteen of those is the
 * reason people stop trusting local platforms.
 *
 * IT ASKS, AND IT NAMES WHAT GOES, because "the worktree is gone" is evidence and
 * not proof: a directory can be missing because somebody moved it, or because an
 * external disk is not mounted. So this proposes and a person decides.
 *
 * The DEFAULT environment is never proposed, whatever happened to its directory.
 * A project that was moved has not been abandoned.
 */
class PruneCommand extends Command
{
    use Refuses;

    protected $signature = 'local:prune
                            {--force : Do not ask}
                            {--json : Machine-readable output}';

    protected $description = 'Remove environments whose worktree no longer exists';

    public function handle(
        EnvironmentRegistry $environments,
        Kubernetes $kubernetes,
        SharedGateway $gateway,
    ): int {
        $all = $environments->all();
        $orphaned = array_values(array_filter($all, static fn (DeployedEnvironment $e): bool => $e->orphaned()));

        if ($this->option('json')) {
            $removed = $this->option('force') ? $this->remove($orphaned, $kubernetes, $gateway) : [];

            $this->line((string) json_encode([
                'environments' => array_map(static fn (DeployedEnvironment $e): array => [
                    'name' => $e->name(),
                    'project' => $e->project,
                    'environment' => $e->environment,
                    'worktree' => $e->worktree,
                    'orphaned' => $e->orphaned(),
                ], $all),
                'removed' => $removed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($orphaned === []) {
            $this->line('  Nothing to prune — every environment here has a worktree.');

            return self::SUCCESS;
        }

        // NAMED, with the directory that is missing. "3 environments" is a
        // number somebody skims; a path they recognise as the branch they merged
        // on Friday is a decision they can actually make.
        $this->line('  These environments have no worktree any more:');

        foreach ($orphaned as $environment) {
            $this->line("      {$environment->name()} — {$environment->worktree}");
        }

        $this->line('  Removing them deletes their databases and volumes.');

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('  Refusing to remove without --force: there is nobody here to ask.');

                return self::FAILURE;
            }

            if (! $this->confirm('Remove them?', default: false)) {
                $this->line('  Left alone.');

                return self::SUCCESS;
            }
        }

        $removed = $this->remove($orphaned, $kubernetes, $gateway);

        foreach ($removed as $name) {
            $this->line("  <fg=green>✓</> {$name} removed.");
        }

        return count($removed) === count($orphaned) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<DeployedEnvironment>  $environments
     * @return list<string>
     */
    private function remove(array $environments, Kubernetes $kubernetes, SharedGateway $gateway): array
    {
        $removed = [];

        foreach ($environments as $environment) {
            if (! $kubernetes->delete('namespace', $environment->namespace, '')) {
                continue;
            }

            // The gateway stops naming this environment's certificate before the
            // certificate goes, exactly as a single removal does.
            $gateway->forget($environment->name());

            $removed[] = $environment->name();
        }

        return $removed;
    }
}
