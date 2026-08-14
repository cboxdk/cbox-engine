<?php

declare(strict_types=1);

namespace Cbox\Engine\Sandbox;

use Cbox\Engine\Contracts\CommandRunner;
use RuntimeException;

/**
 * Building the throwaway application that surrounds a package.
 *
 * SCAFFOLDED WITH DOCKER, RUN IN THE CLUSTER, and the split is deliberate.
 * Composer has to resolve against the PHP the sandbox will actually run — that
 * is the entire point of a version matrix, and resolving on the developer's own
 * PHP would answer a different question. Docker can run an image for another
 * architecture under emulation; containerd inside a kind node refuses to pull
 * one at all. So the one-time resolve happens through Docker and the serving
 * happens in the cluster.
 *
 * IT LIVES INSIDE THE PACKAGE, under `.cbox/sandbox/<combination>`. Anywhere
 * else and the source mount could not reach it — the cluster sees the
 * developer's home directory and nothing above it — and a sandbox beside the
 * package is one somebody can open, read, and delete.
 */
class SandboxBuilder
{
    /** Where a package keeps the applications built around it. */
    public const DIRECTORY = '.cbox/sandbox';

    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * Scaffold one combination, or leave an existing one alone.
     *
     * @return string the directory the sandbox application lives in
     */
    public function build(string $package, string $name, SandboxTarget $target, bool $rebuild = false): string
    {
        $image = $target->image();

        $directory = rtrim($package, '/').'/'.self::DIRECTORY.'/'.$target->environment();

        if (is_dir($directory.'/vendor') && ! $rebuild) {
            return $directory;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create [{$directory}] for the sandbox.");
        }

        $this->ignoreItself(rtrim($package, '/'));

        // A REAL SKELETON, not a hand-written index.php. A package meets
        // middleware, the service container, config discovery and another
        // twenty packages in a real application, and a sandbox that skips all
        // of that proves the package boots rather than that it works.
        // WITH ITS SCRIPTS. `--no-scripts` looks like the cautious choice and
        // is not: Laravel's `post-create-project-cmd` is what copies
        // `.env.example` to `.env` and generates an APP_KEY, so skipping it
        // produced a skeleton that boots into a BindingResolutionException from
        // the encrypter — a 500 with nothing in it about a missing key.
        // Measured, on the first real package.
        $this->composer($image, $directory, $package, [
            'create-project', 'laravel/laravel', '.', $target->constraint(),
            '--no-interaction', '--prefer-dist',
        ]);

        // COPIED BY COMPOSER, AND THEN MOUNTED OVER — which is how an edit is
        // live without the application reading outside its own tree.
        //
        // A symlinked path repository points three directories ABOVE the
        // sandbox, which is above the mount and therefore outside
        // `open_basedir`: the application boots and dies on
        // `include(): open_basedir restriction in effect` for the package's own
        // service provider. Measured, on the first real package.
        //
        // So composer copies — which keeps composer.json and the lock honest —
        // and the manifest mounts the real directory on top of the copy, INSIDE
        // `/var/www/html` where the runtime already allows reading. Widening
        // open_basedir instead would make the sandbox the one place on this
        // platform where an application reads outside its own tree.
        $this->composer($image, $directory, $package, [
            'config', 'repositories.package', '{"type":"path","url":"../../..","options":{"symlink":false}}',
        ]);

        // Also with scripts, so `package:discover` runs and the package's own
        // service provider is registered — which is the entire point of
        // installing it into an application.
        $this->composer($image, $directory, $package, [
            'require', $name.':*@dev', '--no-interaction',
        ]);

        $this->manifest($directory, $target, $name);

        return $directory;
    }

    /**
     * Run composer against the image this sandbox will run on.
     *
     * @param  list<string>  $arguments
     */
    private function composer(string $image, string $directory, string $package, array $arguments): void
    {
        $result = $this->runner->run([
            'docker', 'run', '--rm',
            // The PACKAGE's directory, not the sandbox's: the path repository
            // points three levels up, and composer has to be able to follow it.
            '-v', $package.':'.$package,
            '-w', $directory,
            // Composer writes as the container's user; matching the host's
            // means the developer can still edit what comes out of it.
            '-u', (string) getmyuid().':'.getmygid(),
            '-e', 'COMPOSER_HOME=/tmp/composer',
            '-e', 'COMPOSER_ALLOW_SUPERUSER=1',
            // STRAIGHT TO COMPOSER, past the image's entrypoint. That
            // entrypoint generates nginx and php-fpm configuration under
            // /usr/local/etc as root, and this container runs as the developer
            // so the files it writes are theirs — so it failed with three
            // `Permission denied` lines and `Failed to generate Nginx config`
            // before composer was reached. Measured, on the first real package.
            //
            // None of that configuration matters here: nothing is being served,
            // a dependency graph is being resolved.
            '--entrypoint', 'composer',
            $image,
            ...$arguments,
        ], timeout: 900);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Composer refused inside {$image}\n      ".trim($result->errorOutput ?: $result->text()),
            );
        }
    }

    /**
     * The sandbox's own manifest, so it deploys like anything else.
     */
    private function manifest(string $directory, SandboxTarget $target, string $name): void
    {
        $written = file_put_contents($directory.'/cbox.yaml', <<<YAML
        # Written by `cbox sandbox`. This whole directory is disposable: nothing
        # in it is your work, and deleting it costs a `cbox sandbox` to get back.
        #
        # {$name} is installed here as a path repository pointing at the package
        # three directories up, symlinked — so an edit to the package is live in
        # this application with no reinstall.
        name: {$this->project($name)}
        image: {$target->image()}
        # 80, because that is what the Cbox image's nginx listens on. 8080 was a
        # guess, and a guess here is a Service pointing at a port nothing is
        # bound to: the gateway answers 503 and the pod looks perfectly healthy.
        port: 80
        source: true
        url: APP_URL
        mounts:
          /var/www/html/vendor/{$name}: ../../..

        YAML);

        if ($written === false) {
            throw new RuntimeException("Could not write the sandbox manifest in [{$directory}].");
        }
    }

    /**
     * A composer name as a project name: `cboxdk/laravel-id` → `cboxdk-laravel-id`.
     *
     * The vendor is kept. Two vendors' `laravel-cache` on one machine is not
     * unusual, and a sandbox that silently replaced the other one would be the
     * worst possible version of this feature.
     */
    public function project(string $name): string
    {
        $clean = strtolower((string) preg_replace('~[^a-z0-9]+~i', '-', $name));

        return trim($clean, '-').'-sandbox';
    }

    /**
     * Make `.cbox` ignore itself, rather than editing the package's rules.
     *
     * THIS WRITES A WHOLE LARAVEL APPLICATION INTO SOMEBODY'S REPOSITORY. It is
     * disposable and the command says so — and it still turned up as untracked
     * in `git status`, a few thousand files that a `git add -A` would commit.
     * Measured on this package's own checkout, which is how it was found.
     *
     * A `.gitignore` INSIDE the directory, holding `*`, so the rule arrives with
     * the thing it describes and leaves with it. Appending to the package's own
     * `.gitignore` would edit a file the developer owns, for a directory they did
     * not ask to keep — and it would stay there after the sandbox was deleted.
     *
     * Written once and never overwritten: a developer who edited it meant to.
     */
    private function ignoreItself(string $package): void
    {
        $root = $package.'/.cbox';
        $file = $root.'/.gitignore';

        if (! is_dir($root) || is_file($file)) {
            return;
        }

        // Best effort. A sandbox that works and shows up in `git status` is a
        // far better outcome than one that refuses to build over a file it
        // could not write.
        @file_put_contents($file, "# Written by `cbox sandbox`. Nothing in here is your work.\n*\n");
    }
}
