<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Kind\PublishedPorts;
use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectHealth;
use Cbox\Engine\Project\ProjectLocator;
use Illuminate\Console\Command;

/**
 * Deploy the project in this directory.
 *
 * FINDS THE MANIFEST BY WALKING UP, because a developer runs commands from
 * wherever they happen to be. A tool that only works from the repository root
 * has an unstated precondition.
 *
 * `--dry-run` sends everything through the API server's admission chain and
 * persists nothing, which is the only way to know a change will apply.
 */
class DeployCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:deploy
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--dry-run : Validate against the cluster, change nothing}
                            {--recreate : Delete and rebuild workloads whose shape cannot be changed in place}
                            {--no-wait : Return as soon as the objects are applied}
                            {--json : Machine-readable output}';

    protected $description = 'Deploy the project described by cbox.yaml';

    public function handle(
        ProjectLocator $locator,
        ProjectDeployer $deployer,
        PublishedPorts $published,
        ProjectHealth $health,
    ): int {
        $manifest = $this->locateProject($locator);

        if ($manifest === null) {
            return self::FAILURE;
        }

        $path = (string) $locator->find(is_string($this->option('path')) && $this->option('path') !== ''
            ? $this->option('path')
            : (getcwd() ?: '.'));

        $dryRun = (bool) $this->option('dry-run');

        // The one case where "validated" would be a lie: a project that has
        // never been deployed has no namespace, so the API server has nothing to
        // check the objects against.
        $unseen = $dryRun && ! $deployer->namespaceExists($manifest);

        // The build's own output, as it happens. A `docker build` of a real
        // application is minutes, and a command that prints nothing for minutes
        // is indistinguishable from one that has hung.
        $outcome = $deployer->deploy(
            $manifest,
            $dryRun,
            (bool) $this->option('recreate'),
            $this->option('json') ? null : function (string $chunk): void {
                $this->output->write($chunk);
            },
        );

        // A DEPLOY THAT REPORTS SUCCESS AND SERVES NOTHING is the worst answer
        // this can give, and it is the easy one to write: the objects applied,
        // and the pod is in ImagePullBackOff where only `kubectl describe` will
        // ever say why.
        $blocked = $outcome->succeeded && ! $dryRun && ! $this->option('no-wait')
            ? $health->awaitReady($manifest->namespace())
            : null;
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'manifest' => $path,
                'name' => $manifest->name,
                'namespace' => $manifest->namespace(),
                'domains' => $manifest->domains,
                'dryRun' => $dryRun,
                'validated' => ! $unseen,
                'succeeded' => $outcome->succeeded,
                'objects' => $outcome->applied,
                'failure' => $outcome->failure,
                'running' => $blocked === null,
                'blocked' => $blocked,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $outcome->succeeded && $blocked === null ? self::SUCCESS : self::FAILURE;
        }

        if (! $outcome->succeeded) {
            $this->error("  {$manifest->name} was not deployed.");
            // The API server's own words: a webhook, an immutable field or a
            // missing kind says something specific.
            $this->line("      {$outcome->failure}");

            // One failure has an answer this command can offer, so it does —
            // rather than leaving a wall of Kubernetes text and a person
            // guessing. Offered, not taken: recreating a workload is a brief
            // outage and that is the developer's call.
            if ($outcome->blockedByImmutableField()) {
                $this->newLine();
                $this->line('      Something about this workload cannot be changed in place —');
                $this->line('      a Deployment\'s selector, most likely, which Kubernetes freezes');
                $this->line('      when the object is created.');
                $this->line('      <fg=yellow>cbox deploy --recreate</> deletes and rebuilds it. Brief downtime.');
            }

            return self::FAILURE;
        }

        if ($unseen) {
            $this->line("  <fg=yellow>!</> {$manifest->name} has never been deployed, so there is nothing to");
            $this->line('      check it against yet. The manifest itself is readable — deploy once, and');
            $this->line('      dry runs after that go through the cluster.');

            return self::SUCCESS;
        }

        if ($blocked !== null) {
            $this->error('  '.$this->label($manifest).' was applied, and it is not running.');
            $this->line("      {$blocked}");

            return self::FAILURE;
        }

        $this->line($dryRun
            ? '  <fg=green>✓</> '.$this->label($manifest)." would apply — {$outcome->applied} objects, nothing changed."
            : '  <fg=green>✓</> '.$this->label($manifest)." deployed — {$outcome->applied} objects.");

        $ports = $published->current();

        foreach ($manifest->domains as $domain) {
            $this->line('      '.$ports->url($domain));
        }

        return self::SUCCESS;
    }
}
