<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Platform\Service\SourceMount;

/**
 * The packages a developer is editing next door, mounted so the pod can see them.
 *
 * A COMPOSER PATH REPOSITORY IS A SYMLINK OUT OF THE PROJECT. `{"type":"path",
 * "url":"../laravel-id"}` installs `vendor/acme/thing` as a link to a sibling
 * checkout, which is the ordinary way to work on a package and its consumer at
 * once. It resolves perfectly on the machine and dangles inside the container,
 * because only the project directory is mounted.
 *
 * AND IT FAILS IN THE CRUELLEST WAY. The application boots under Herd and the pod
 * dies with "Failed to open stream: No such file or directory" naming a file that
 * is plainly there — the developer checks the path, finds it, and has nowhere to
 * go. Measured on a real project: a queue worker crash-looping for a day over a
 * package the host could see the whole time.
 *
 * READ FROM composer.json, NOT BY SCANNING. The path repositories are declared,
 * so this asks the file rather than walking a vendor tree of thousands of
 * directories looking for links. What is declared is what composer linked.
 *
 * MOUNTED WHERE THE LINK POINTS, which is a path outside the application root:
 * `vendor/acme/thing -> ../../../thing` seen from `/var/www/html/vendor/acme`
 * resolves to `/var/www/thing`, so that is where the sibling has to appear.
 */
class LinkedPackages
{
    /**
     * Every sibling checkout this project links to, as mounts.
     *
     * @param  string  $projectPath  the project on this machine
     * @param  string  $appPath  where the project is mounted in the container
     * @return list<SourceMount>
     */
    public function forProject(string $projectPath, string $appPath): array
    {
        $projectPath = rtrim($projectPath, '/');
        $appPath = rtrim($appPath, '/');

        $mounts = [];

        foreach ($this->pathRepositories($projectPath) as $relative) {
            $host = realpath($projectPath.'/'.$relative);

            if ($host === false || ! is_dir($host)) {
                continue;
            }

            // Where a link into it lands from inside the container. The link is
            // relative to the vendor directory, so the same relative journey from
            // the mounted application root is where the target has to be.
            $inside = $this->normalise($appPath.'/'.$relative);

            // A sibling that happens to live INSIDE the project is already
            // mounted with it, and mounting it over itself would shadow the
            // directory with a copy of the same thing.
            if ($inside === $appPath || str_starts_with($inside.'/', $appPath.'/')) {
                continue;
            }

            $mounts[$inside] = new SourceMount($host, $inside);
        }

        return array_values($mounts);
    }

    /**
     * The `url` of every path repository in the project's composer.json.
     *
     * @return list<string>
     */
    private function pathRepositories(string $projectPath): array
    {
        $file = $projectPath.'/composer.json';

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        $repositories = is_array($decoded) ? ($decoded['repositories'] ?? null) : null;

        if (! is_array($repositories)) {
            return [];
        }

        $paths = [];

        // Composer accepts a list OR an object keyed by name, and a project using
        // the second form would otherwise look like it has none.
        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;

            // An absolute url is somebody's own machine and cannot be relative to
            // the project; a wildcard is a set of them. Both are left alone rather
            // than guessed at — see the refusal in ProjectDeployer.
            if (is_string($url) && $url !== '' && ! str_contains($url, '*') && ! str_starts_with($url, '/')) {
                $paths[] = rtrim($url, '/');
            }
        }

        return $paths;
    }

    /** Resolve `..` in a path without touching the filesystem, which has no such directory. */
    private function normalise(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
