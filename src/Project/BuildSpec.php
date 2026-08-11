<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use RuntimeException;

/**
 * What to build, for a project that brings its own image.
 */
readonly class BuildSpec
{
    /**
     * @param  array<string, string>  $args
     */
    public function __construct(
        public string $context,
        public string $dockerfile,
        public string $target = '',
        public array $args = [],
    ) {}

    /**
     * Read the `build:` block, relative to the directory the manifest is in.
     *
     * RELATIVE TO THE MANIFEST, never to the working directory. `cbox deploy`
     * runs from wherever somebody happens to be — the whole point of walking up
     * to find the file — so a Dockerfile resolved against the shell's cwd is one
     * that is found on Tuesday and missing on Wednesday.
     */
    public static function fromManifest(mixed $parsed, string $directory, ?GithubToken $github = null): ?self
    {
        if ($parsed === null || $parsed === false) {
            return null;
        }

        // `build: true` — the ordinary case, and the shortest thing to write for
        // a project whose Dockerfile is where Dockerfiles are.
        if ($parsed === true) {
            return self::at($directory, 'Dockerfile', '', []);
        }

        if (! is_array($parsed)) {
            throw new RuntimeException(
                '`build` is either yes, or a mapping with `dockerfile`, `context`, `target` and `args`.',
            );
        }

        $context = is_string($parsed['context'] ?? null) ? $parsed['context'] : '.';
        $dockerfile = is_string($parsed['dockerfile'] ?? null) ? $parsed['dockerfile'] : 'Dockerfile';
        $target = is_string($parsed['target'] ?? null) ? $parsed['target'] : '';

        $args = [];

        foreach (is_array($parsed['args'] ?? null) ? $parsed['args'] : [] as $name => $value) {
            if (! is_string($name) || ! is_scalar($value)) {
                throw new RuntimeException('Every entry under `build.args` is a name and a plain value.');
            }

            $args[$name] = self::resolve($name, (string) $value, $directory, $github);
        }

        return self::at(
            rtrim($directory, '/').'/'.ltrim($context, './'),
            $dockerfile,
            $target,
            $args,
        );
    }

    /**
     * A build argument, with `${VAR}` taken from the environment.
     *
     * BECAUSE SOME OF THEM ARE CREDENTIALS. One application here takes a
     * `NODE_AUTH_TOKEN` to reach a private npm registry, and a token written
     * into `cbox.yaml` is a token committed to a repository. So the manifest
     * names the variable and the value comes from the shell.
     *
     * TAKEN FROM `gh` WHEN IT IS A GITHUB ONE, and only then — see
     * {@see GithubToken}. The shell still wins, because a developer who exported
     * something meant it.
     *
     * REFUSED WHEN UNSET, rather than passed through empty: an empty token
     * produces `401 Unauthorized` several minutes into a build, which reads
     * like a broken registry rather than a missing variable. Measured, on
     * exactly that build.
     *
     * Worth saying plainly: a build ARG is visible in the image's history. Where
     * a Dockerfile supports `RUN --mount=type=secret`, that is the better
     * mechanism and this is not it.
     */
    private static function resolve(
        string $name,
        string $value,
        string $directory,
        ?GithubToken $github,
    ): string {
        if (preg_match('~^\$\{([A-Za-z_][A-Za-z0-9_]*)\}$~', $value, $matches) !== 1) {
            return $value;
        }

        $from = getenv($matches[1]);

        if (is_string($from) && $from !== '') {
            return $from;
        }

        $borrowed = $github?->forVariable($matches[1], $directory);

        if (is_string($borrowed) && $borrowed !== '') {
            return $borrowed;
        }

        throw new RuntimeException(
            "`build.args.{$name}` reads {$value} from the environment, and [{$matches[1]}] is not "
            .'set here. Export it and deploy again — or, for a GitHub Packages registry named in '
            .'this project\'s .npmrc, sign in once with `gh auth login` and it is taken from there.',
        );
    }

    /**
     * @param  array<string, string>  $args
     */
    private static function at(string $context, string $dockerfile, string $target, array $args): self
    {
        $context = rtrim((string) (realpath($context) ?: $context), '/');
        $path = str_starts_with($dockerfile, '/') ? $dockerfile : $context.'/'.$dockerfile;

        // REFUSED HERE, not by docker. `docker build` answers a missing
        // Dockerfile with a message about a build context, which sends somebody
        // looking at the wrong thing.
        if (! is_file($path)) {
            throw new RuntimeException(
                "There is no Dockerfile at [{$path}]. Name it with `build.dockerfile`, relative to "
                .'this manifest.',
            );
        }

        return new self($context, $path, $target, $args);
    }
}
