<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Addons\AddonInstaller;
use Cbox\Engine\Addons\AddonSet;
use Cbox\Engine\ValueObjects\AddonResult;
use Illuminate\Console\Command;

/**
 * Install the platform's addons into the local cluster.
 *
 * Separate from `cbox up` while it is being built, and folded into it once it is
 * proven — a developer should never have to know this step exists.
 *
 * `--dry-run` goes through the real admission chain and persists nothing, which
 * is the only way to know a change will apply. It is also how this is tested
 * against a cluster without changing it.
 */
class AddonsCommand extends Command
{
    protected $signature = 'local:addons {--dry-run : Validate against the cluster, change nothing} {--json : Machine-readable output}';

    protected $description = 'Install the gateway, Gateway API and cert-manager into the local cluster';

    public function handle(AddonInstaller $installer, AddonSet $addons): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->option('json')) {
            $versions = $addons->versions();
            $this->line('  '.($dryRun ? 'Validating' : 'Installing').' addons: '
                .implode(', ', array_map(
                    static fn (string $k, string $v): string => "{$k} {$v}",
                    array_keys($versions),
                    array_values($versions),
                )));
        }

        $results = $installer->install($dryRun);
        $failed = array_filter($results, static fn (AddonResult $r): bool => ! $r->succeeded);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'dryRun' => $dryRun,
                'addons' => array_map(static fn (AddonResult $r): array => $r->toArray(), $results),
            ], JSON_PRETTY_PRINT));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $result) {
            if ($result->succeeded) {
                $this->line("  <fg=green>✓</> {$result->name} — {$result->objects} objects");

                continue;
            }

            $this->error("  ✗ {$result->name}");
            // The API server's own words, not a summary of them.
            $this->line("      {$result->failure}");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
