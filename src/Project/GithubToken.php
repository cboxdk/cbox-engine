<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\CommandRunner;

/**
 * The GitHub token a build needs, taken from the login the developer already has.
 *
 * BECAUSE THE ALTERNATIVE WAS A SENTENCE IN A README. that application's Dockerfile
 * installs `@example-app/*` from GitHub Packages, its `.npmrc` reads
 * `_authToken=${NODE_AUTH_TOKEN}`, and nothing on a developer's machine exports
 * that. So the build failed several minutes in with a 401, and the fix was
 * "export a token first" — a step that is forgotten once and then debugged as a
 * broken registry. `gh` is already signed in on any machine that cloned the
 * repository; the token is right there.
 *
 * IT MUST BE ASKING GITHUB. A token is handed over only when the project's own
 * `.npmrc` binds that variable on a `npm.pkg.github.com` line — proof that the
 * value is going to GitHub and not to some other registry that happens to read
 * a variable by the same name. Handing a GitHub credential to whatever asked
 * last is how a credential ends up somewhere it was never meant to go.
 *
 * THE VALUE IS NEVER PRINTED, by this class or by anything holding it. It goes
 * straight into a `--build-arg`.
 */
class GithubToken
{
    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * The developer's token, if this variable is one GitHub Packages will read.
     *
     * @param  string  $variable  the name inside `${…}` in the manifest
     * @param  string  $directory  the project's own directory
     */
    public function forVariable(string $variable, string $directory): ?string
    {
        if (! $this->boundToGithubPackages($variable, $directory)) {
            return null;
        }

        $result = $this->runner->run(['gh', 'auth', 'token'], timeout: 10);

        if (! $result->successful()) {
            return null;
        }

        $token = trim($result->text());

        return $token === '' ? null : $token;
    }

    /**
     * Whether `.npmrc` sends this variable to GitHub Packages.
     *
     * Line by line rather than across the file: `.npmrc` is a list of
     * per-registry settings, and a file mentioning GitHub somewhere while
     * binding the variable on an npmjs line is exactly the case this refuses.
     */
    private function boundToGithubPackages(string $variable, string $directory): bool
    {
        $path = rtrim($directory, '/').'/.npmrc';

        if (! is_file($path)) {
            return false;
        }

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (! str_contains($line, 'npm.pkg.github.com')) {
                continue;
            }

            if (str_contains($line, '${'.$variable.'}')) {
                return true;
            }
        }

        return false;
    }
}
