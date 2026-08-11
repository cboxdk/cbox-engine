<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\ClusterManager;
use Illuminate\Console\Command;

/**
 * Stop the local cluster, and do not destroy it.
 *
 * The opposite of `up` has to be the CHEAP opposite. A stopped cluster keeps
 * every volume, image and object and comes back in seconds; a destroyed one
 * takes the developer's databases with it and costs minutes to rebuild. A
 * command named `down` that did the second would be the most expensive mistake
 * this tool could offer, and it would offer it to somebody in a hurry.
 *
 * Destroying is `cbox destroy`, and it says so.
 */
class DownCommand extends Command
{
    protected $signature = 'local:down {--json : Machine-readable output}';

    protected $description = 'Stop the local cluster, keeping everything in it';

    public function handle(ClusterManager $cluster): int
    {
        $state = $cluster->down();

        if ($this->option('json')) {
            $this->line((string) json_encode($state->toArray(), JSON_PRETTY_PRINT));

            return $state->failure === '' ? self::SUCCESS : self::FAILURE;
        }

        if ($state->failure !== '') {
            $this->error("  {$state->failure}");

            return self::FAILURE;
        }

        $this->line($state->changed
            ? "  <fg=green>✓</> Cluster [{$state->name}] stopped. Everything in it is kept."
            : "  Cluster [{$state->name}] was not running ({$state->phase->value}).");

        return self::SUCCESS;
    }
}
