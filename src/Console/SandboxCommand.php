<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Project\WorktreeEnvironment;
use Cbox\Engine\Sandbox\SandboxBuilder;
use Cbox\Engine\Sandbox\SandboxMatrix;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Throwable;

/**
 * Raise a throwaway application around the package in this directory.
 *
 * A PACKAGE HAS NO HTTP SURFACE until something installs it. Today that means
 * testbench in a terminal, which proves the unit behaviour and shows you
 * nothing: no request, no session, no gateway, no other package alongside it,
 * and no browser.
 *
 * On the DEV TIER of the base images, because a package author is exactly the
 * person who wants to step through a request — Xdebug, SPX and pcov are there.
 * The standard tier is what an application ships on; this is not an
 * application.
 *
 * A MATRIX, because that is the actual question. "Does it work on 8.3 AND 8.4
 * with Laravel 12 AND 13" is four applications, which is what a laptop with one
 * PHP cannot give and what a cluster can.
 */
class SandboxCommand extends Command
{
    use Refuses;

    protected $signature = 'local:sandbox
                            {--path= : The package directory, defaulting to this one}
                            {--php= : PHP versions, comma separated (8.4)}
                            {--laravel= : Laravel majors, comma separated (13)}
                            {--rebuild : Resolve dependencies again even if they are already there}
                            {--no-deploy : Scaffold, and do not put anything on the cluster}
                            {--json : Machine-readable output}';

    protected $description = 'Raise a throwaway Laravel application around this package';

    public function handle(SandboxBuilder $builder, Kernel $artisan, WorktreeEnvironment $worktrees): int
    {
        $option = $this->option('path');
        $package = is_string($option) && $option !== '' ? $option : (getcwd() ?: '.');
        $package = rtrim((string) (realpath($package) ?: $package), '/');

        try {
            $name = $this->packageName($package);
            $matrix = SandboxMatrix::parse(
                is_string($this->option('php')) ? $this->option('php') : '',
                is_string($this->option('laravel')) ? $this->option('laravel') : '',
            );
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        // THE WORKTREE COMES FIRST, when there is one. A package developed in
        // two worktrees at once would otherwise put both their sandboxes in the
        // same environment — `php84-laravel13` from one branch and the other,
        // one namespace, whichever deployed last winning. The combination alone
        // is not a unique name for a copy of a package.
        $worktree = $worktrees->at($package);

        $built = [];

        foreach ($matrix->targets() as $target) {
            // Not under `--json`: a progress line in front of a JSON document
            // makes the document unparseable, and `--json` exists so an agent
            // does not have to guess where the output starts.
            if (! $this->option('json')) {
                $this->line("  Building {$target->environment()} — PHP {$target->php}, Laravel {$target->laravel}…");
            }

            try {
                $directory = $builder->build($package, $name, $target, (bool) $this->option('rebuild'));
            } catch (Throwable $e) {
                return $this->refuse($e->getMessage());
            }

            // Keyed by the ENVIRONMENT it lands in, not by the combination:
            // those are the same word only when the package is not in a
            // worktree, and the environment is what somebody types into
            // `cbox logs --env`.
            $environment = $worktree->isDefault()
                ? $target->environment()
                : $worktree->name.'-'.$target->environment();

            $built[$environment] = $directory;

            if ($this->option('no-deploy')) {
                continue;
            }

            // Deployed as an ENVIRONMENT of one project, which is what §6 of the
            // plan already means by the word: its own namespace, its own
            // hostname, its own database. Nothing had to be invented for this.

            $exit = $artisan->call('local:deploy', [
                '--path' => $directory,
                '--env' => $environment,
            ], $this->output);

            if ($exit !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'package' => $name,
                'project' => $builder->project($name),
                'sandboxes' => $built,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=green>✓</> '.count($built).' sandbox'.(count($built) === 1 ? '' : 'es')." for {$name}.");
        $this->line('      The package is mounted over its own copy, so an edit is live.');
        $this->line('      Nothing under .cbox/sandbox is your work — delete it whenever.');

        return self::SUCCESS;
    }

    /**
     * The composer name of the package being sandboxed.
     *
     * REFUSED FOR AN APPLICATION. Running this in a Laravel app would scaffold a
     * second Laravel app inside it and install the app into itself, which fails
     * somewhere deep in composer with a message about a circular reference —
     * long after it has written several hundred megabytes into the repository.
     */
    private function packageName(string $package): string
    {
        $path = $package.'/composer.json';

        if (! is_file($path)) {
            throw new RuntimeException("There is no composer.json in [{$package}], so there is no package to sandbox.");
        }

        /** @var mixed $parsed */
        $parsed = json_decode((string) file_get_contents($path), true);
        $name = is_array($parsed) ? ($parsed['name'] ?? null) : null;

        if (! is_string($name) || ! str_contains($name, '/')) {
            throw new RuntimeException('That composer.json has no `name`, so nothing can require it.');
        }

        if (is_file($package.'/artisan')) {
            throw new RuntimeException(
                "[{$name}] is an application, not a package — it has an artisan. Deploy it with "
                .'`cbox deploy`; a sandbox is for something that has no application of its own.',
            );
        }

        return $name;
    }
}
