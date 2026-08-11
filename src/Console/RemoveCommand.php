<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Platform\SharedGateway;
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
 */
class RemoveCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:remove
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--force : Do not ask}
                            {--json : Machine-readable output}';

    protected $description = 'Remove the project in this directory from the cluster, with its data';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes, SharedGateway $gateway): int
    {
        $manifest = $this->locateProject($locator);

        if ($manifest === null) {
            return self::FAILURE;
        }

        $namespace = $manifest->namespace();

        if ($kubernetes->read('namespace', $namespace, '') === null) {
            $this->line('  ['.$this->label($manifest).'] is not on the cluster.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirmRemoval($this->label($manifest), $manifest->resources)) {
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
        $gatewayUpdated = $removed && $gateway->forget($manifest->deployedName());

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'name' => $manifest->deployedName(),
                'environment' => $manifest->environment->name,
                'namespace' => $namespace,
                'removed' => $removed,
                'gateway_updated' => $gatewayUpdated,
            ], JSON_PRETTY_PRINT));

            return $removed && $gatewayUpdated ? self::SUCCESS : self::FAILURE;
        }

        if (! $removed) {
            $this->error('  ['.$this->label($manifest).'] could not be removed.');

            return self::FAILURE;
        }

        if (! $gatewayUpdated) {
            $this->error('  ['.$this->label($manifest).'] is gone, but the gateway still names its certificate. Deploy anything to put it back in step.');

            return self::FAILURE;
        }

        $this->line('  <fg=green>✓</> ['.$this->label($manifest).'] removed.');

        return self::SUCCESS;
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
