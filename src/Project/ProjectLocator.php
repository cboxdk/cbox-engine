<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use RuntimeException;

/**
 * The project a person is standing in, in the environment they are standing in.
 *
 * ONE PLACE, because every command needs the same three steps — walk up for the
 * manifest, read it, work out which environment this directory is — and six
 * copies of that is six chances for one command to see a different environment
 * than the one beside it. A `cbox logs` that reads the default environment while
 * `cbox deploy` wrote a worktree's is a tool that shows somebody the wrong logs
 * and gives them no reason to doubt it.
 */
class ProjectLocator
{
    public function __construct(
        private readonly ProjectManifestReader $reader,
        private readonly WorktreeEnvironment $worktrees,
    ) {}

    /**
     * @param  string|null  $override  an environment named on the command line;
     *                                 an empty string means the default one, and
     *                                 null means "whatever this directory is"
     *
     * @throws RuntimeException when there is no manifest, or it does not read
     */
    public function locate(string $from, ?string $override = null): ProjectManifest
    {
        $path = $this->reader->find($from);

        if ($path === null) {
            throw new RuntimeException(
                'No '.ProjectManifestReader::FILENAME.' here, or in any directory above it.',
            );
        }

        $manifest = $this->reader->read($path);

        $environment = $override === null
            ? $this->worktrees->at(dirname($path))
            : Environment::named($override);

        return $manifest->in($environment)->at(dirname($path));
    }

    /** Where the manifest for this directory is, if there is one. */
    public function find(string $from): ?string
    {
        return $this->reader->find($from);
    }
}
