<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectLocator;
use Cbox\Engine\Tunnel\CloudflareTunnel;
use Illuminate\Console\Command;

/**
 * Take this project back off the public internet.
 *
 * THE HALF THAT MATTERS MORE THAN ITS COUNTERPART. A tunnel is a public address
 * into a laptop, and the way one is forgotten is that closing it was harder than
 * opening it. This is one word.
 *
 * The credentials go with it. A Secret holding a Cloudflare tunnel token, left
 * in a namespace after the tunnel it authorised was stopped, is a live credential
 * nobody is thinking about any more.
 */
class UnexposeCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:unexpose
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--json : Machine-readable output}';

    protected $description = 'Stop this project\'s Cloudflare tunnel';

    public function handle(ProjectLocator $locator, Kubernetes $kubernetes): int
    {
        $manifest = $this->locateProject($locator);

        if ($manifest === null) {
            return self::FAILURE;
        }

        $namespace = $manifest->namespace();
        $name = CloudflareTunnel::NAME;

        // The connector first, so the address stops answering before its
        // credentials are taken away rather than after.
        $stopped = $kubernetes->delete('deployment', $name, $namespace);
        $kubernetes->delete('configmap', $name, $namespace);
        $kubernetes->delete('secret', $name, $namespace);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'name' => $manifest->deployedName(),
                'environment' => $manifest->environment->name,
                'namespace' => $namespace,
                'stopped' => $stopped,
            ], JSON_PRETTY_PRINT));

            return $stopped ? self::SUCCESS : self::FAILURE;
        }

        if (! $stopped) {
            $this->error('  ['.$this->label($manifest)."]'s tunnel could not be stopped.");

            return self::FAILURE;
        }

        $this->line('  <fg=green>✓</> ['.$this->label($manifest).'] is no longer reachable from outside.');

        return self::SUCCESS;
    }
}
