<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Platform\SharedGateway;
use Cbox\Engine\Project\EnvironmentRegistry;
use Cbox\Engine\Project\ProjectLocator;
use Cbox\Engine\Project\ResourceSpec;
use Illuminate\Console\Command;

/**
 * Take the project in this directory back off the cluster.
 *
 * THE HALF THAT DID NOT EXIST. A tool that only deploys leaves a laptop full of
 * namespaces from projects somebody stopped working on months ago, each holding
 * a database, a volume and a pod — and the only way out was `kubectl`, which is
 * the thing this product exists to not require.
 *
 * IT ASKS, AND IT SAYS WHAT GOES. Deleting the namespace takes the databases and
 * their volumes with it, and "I removed the project" and "I deleted my data" are
 * the same command here. Naming the resources before asking is the difference
 * between a confirmation and a formality.
 *
 * `--force` exists for scripts and agents, which cannot answer a prompt, and it
 * has to be typed.
 *
 * AND `--project`, FOR THE ONE THIS COULD NOT REACH. Everything above starts by
 * reading a `cbox.yaml`, so a project whose directory has been deleted — the
 * ordinary end of a project, `rm -rf` on a checkout somebody finished with — had
 * no way off the cluster at all. `cbox prune` does not offer it either: it
 * deliberately never proposes a DEFAULT environment, because a project that was
 * moved has not been abandoned. Between the two, the namespace, its Postgres and
 * its volume stayed forever, which is the exact outcome prune exists to prevent.
 *
 * Named against the cluster rather than a file, because the cluster is what
 * still has it.
 */
class RemoveCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:remove
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--project= : Remove it by the name the cluster knows, for a project whose directory is gone}
                            {--force : Do not ask}
                            {--json : Machine-readable output}';

    protected $description = 'Remove the project in this directory from the cluster, with its data';

    public function handle(
        ProjectLocator $locator,
        Kubernetes $kubernetes,
        SharedGateway $gateway,
        EnvironmentRegistry $environments,
    ): int {
        $named = $this->stringOption('project');

        $target = $named !== null
            ? $this->fromCluster($named, $environments)
            : $this->fromManifest($locator);

        if ($target === null) {
            return self::FAILURE;
        }

        [$deployedName, $environmentName, $namespace, $label, $resources] = $target;

        if ($kubernetes->read('namespace', $namespace, '') === null) {
            $this->line("  [{$label}] is not on the cluster.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirmRemoval($label, $resources)) {
            $this->line('  Left alone.');

            return self::FAILURE;
        }

        // The namespace, and everything Kubernetes garbage-collects with it.
        // Deleting the objects one at a time would leave whatever the compiler
        // has learned to emit since this command was written.
        $removed = $kubernetes->delete('namespace', $namespace, '');

        // The gateway is shared, and this project had a certificate on it. Put
        // it back in step before reporting success — a removal that leaves the
        // gateway naming a secret nobody creates takes every OTHER project on
        // this machine off the air.
        $gatewayUpdated = $removed && $gateway->forget($deployedName);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'name' => $deployedName,
                'environment' => $environmentName,
                'namespace' => $namespace,
                'removed' => $removed,
                'gateway_updated' => $gatewayUpdated,
            ], JSON_PRETTY_PRINT));

            return $removed && $gatewayUpdated ? self::SUCCESS : self::FAILURE;
        }

        if (! $removed) {
            $this->error("  [{$label}] could not be removed.");

            return self::FAILURE;
        }

        if (! $gatewayUpdated) {
            $this->error("  [{$label}] is gone, but the gateway still names its certificate. Deploy anything to put it back in step.");

            return self::FAILURE;
        }

        $this->line("  <fg=green>✓</> [{$label}] removed.");

        return self::SUCCESS;
    }

    /**
     * The project in a directory, which is how this is nearly always used.
     *
     * @return array{string, string, string, string, list<ResourceSpec>}|null
     */
    private function fromManifest(ProjectLocator $locator): ?array
    {
        $manifest = $this->locateProject($locator);

        return $manifest === null ? null : [
            $manifest->deployedName(),
            $manifest->environment->name,
            $manifest->namespace(),
            $this->label($manifest),
            $manifest->resources,
        ];
    }

    /**
     * An environment named against the cluster, for when there is no file left.
     *
     * NO RESOURCE LIST, and that is not a gap to fill later. The names come from
     * a manifest this project no longer has, so the confirmation says plainly
     * that everything in the namespace goes rather than listing two databases
     * and quietly omitting a third the file used to mention.
     *
     * @return array{string, string, string, string, list<ResourceSpec>}|null
     */
    private function fromCluster(string $named, EnvironmentRegistry $environments): ?array
    {
        foreach ($environments->all() as $environment) {
            if ($environment->name() === $named) {
                return [
                    $environment->name(),
                    $environment->environment,
                    $environment->namespace,
                    $environment->name(),
                    [],
                ];
            }
        }

        // NAMED BACK, with what there was to choose from. A typo and a project
        // that was never deployed produce the same silence otherwise, and the
        // names are not guessable — an environment is `project-environment`.
        $this->error("  [{$named}] is not an environment on this cluster.");

        $known = array_map(
            static fn ($environment): string => $environment->name(),
            $environments->all(),
        );

        if ($known !== []) {
            $this->line('      There is: '.implode(', ', $known));
        }

        return null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<ResourceSpec>  $resources
     */
    private function confirmRemoval(string $name, array $resources): bool
    {
        if (! $this->input->isInteractive()) {
            $this->error('  Refusing to remove without --force: there is nobody here to ask.');

            return false;
        }

        // NAMED, not counted. "and its 2 resources" is a number somebody skims;
        // "db (postgres), cache (valkey)" is the thing they are about to lose.
        if ($resources !== []) {
            $this->line('  This also deletes, with their data:');

            foreach ($resources as $resource) {
                $this->line("      {$resource->name} ({$resource->engine->value})");
            }
        }

        return $this->confirm("Remove [{$name}] from the cluster?", default: false);
    }
}
