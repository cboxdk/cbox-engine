<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Contracts\HostResolver;
use Cbox\Engine\Platform\ClusterObjects;
use Illuminate\Console\Command;

/**
 * Take Cbox Local off this machine.
 *
 * A TOOL THAT CANNOT BE REMOVED IS ONE PEOPLE HESITATE TO INSTALL, and the
 * hesitation is earned: a local platform touches a container runtime, a
 * resolver file in /etc, and however many gigabytes of images. "Delete the
 * directory" leaves all three.
 *
 * IT NAMES EVERYTHING BEFORE IT TOUCHES ANYTHING, including the parts it cannot
 * remove itself. The resolver file needs administrator rights, so this prints
 * the exact command rather than asking for a password at the end of a
 * destructive operation — which is the worst possible moment to ask for one.
 *
 * The cluster goes with everything on it. Every database, every volume, every
 * project. That is the whole point of the command and it is also the reason it
 * asks.
 */
class UninstallCommand extends Command
{
    use Refuses;

    protected $signature = 'local:uninstall
                            {--force : Do not ask}
                            {--json : Machine-readable output}';

    protected $description = 'Remove the cluster and this machine\'s Cbox Local settings';

    public function handle(ClusterManager $cluster, HostResolver $resolver): int
    {
        $state = $cluster->state();
        $resolverState = $resolver->state();

        // `--json` IS NOT CONSENT. The first version returned here immediately,
        // so `cbox uninstall --json` destroyed the cluster and everything on it
        // without asking anything — and it did, to a cluster with four
        // applications on it, thirty seconds after being written.
        //
        // A machine-readable answer is a different SHAPE of answer, never a
        // different decision. Nobody can answer a prompt through a JSON stream,
        // so the only consent that counts there is `--force`.
        if ($this->option('json')) {
            if (! $this->option('force')) {
                return $this->refuse(
                    'Refusing to uninstall without --force. This removes the cluster and every database '
                    .'on it, and --json cannot ask.',
                    ['cluster' => $state->toArray(), 'resolver' => ['path' => $resolver->path()]],
                );
            }

            return $this->uninstall($cluster, $resolver, json: true);
        }

        $this->line('  This removes, from this machine:');
        $this->line("      the [{$state->name}] cluster and everything on it — every project, every");
        $this->line('      database, every volume, with no way back');

        if ($resolverState->present) {
            $this->line("      {$resolver->path()}, so *.".ClusterObjects::DOMAIN.' stops resolving');
        }

        // SAID PLAINLY, because somebody uninstalling wants the disk back and
        // would otherwise wonder where it went. This does not delete images: a
        // machine that also runs other containers shares that cache, and
        // reclaiming it is `docker system prune`, which is theirs to run.
        $this->line('  It does not remove downloaded images — that cache is shared with everything');
        $this->line('  else you run in Docker. `docker system prune -a` is how you get those back.');

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                return $this->refuse('Refusing to uninstall without --force: there is nobody here to ask.');
            }

            if (! $this->confirm('Remove Cbox Local from this machine?', default: false)) {
                $this->line('  Left alone.');

                return self::SUCCESS;
            }
        }

        return $this->uninstall($cluster, $resolver, json: false);
    }

    private function uninstall(ClusterManager $cluster, HostResolver $resolver, bool $json): int
    {
        $destroyed = $cluster->destroy();
        $path = $resolver->path();
        $present = $resolver->state()->present;

        if ($json) {
            $this->line((string) json_encode([
                'cluster' => $destroyed->toArray(),
                'resolver' => ['path' => $path, 'present' => $present, 'command' => 'sudo rm '.$path],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $destroyed->failure === '' ? self::SUCCESS : self::FAILURE;
        }

        if ($destroyed->failure !== '') {
            return $this->refuse('The cluster could not be removed: '.$destroyed->failure);
        }

        $this->line("  <fg=green>✓</> The [{$destroyed->name}] cluster is gone.");

        if ($present) {
            // NOT RUN FOR THEM. This is the one file that needs administrator
            // rights, and asking for a password after the destructive part is
            // done is asking at the worst possible moment. One line, theirs to
            // paste.
            $this->newLine();
            $this->line('  One file is left, and removing it needs administrator rights:');
            $this->line("      <options=bold>sudo rm {$path}</>");
        }

        return self::SUCCESS;
    }
}
