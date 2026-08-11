<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\CommandRunner;

/**
 * Working out which environment a directory is.
 *
 * ASKED OF GIT, not of a file. A `.cbox-env` alongside the manifest would be one
 * more thing to create, to forget, and to commit by accident into somebody
 * else's checkout — and the answer is already sitting in the repository.
 *
 * THE MAIN CHECKOUT IS THE DEFAULT ENVIRONMENT whatever branch it happens to be
 * on. Naming the environment after the branch everywhere would mean that
 * switching branches in the directory somebody works in every day silently
 * builds them a second environment, with a second database, empty. A worktree is
 * a deliberate act; checking out a branch is not.
 */
class WorktreeEnvironment
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function at(string $path): Environment
    {
        // WHETHER THIS DIRECTORY IS A LINKED WORKTREE, asked in the one way that
        // does not depend on comparing paths. git puts a linked worktree's own
        // git dir inside `<repo>/.git/worktrees/<name>`, and nothing else lives
        // there — so the answer is in the path's shape, not in whether two
        // strings for the same directory happen to match.
        //
        // They do not, in practice. The first version compared
        // `--git-common-dir` with `--absolute-git-dir`, and on a case-insensitive
        // filesystem — every default Mac — a project opened as
        // `~/Projects/Example` whose repository git canonicalises as
        // `~/Projects/example` came back as two different paths. It deployed as
        // a phantom `master` environment, with its own namespace and its own
        // empty database, and nothing anywhere said why.
        $own = $this->git($path, ['rev-parse', '--absolute-git-dir']);

        if (! str_contains($own, '/.git/worktrees/')) {
            return Environment::default();
        }

        $branch = $this->git($path, ['branch', '--show-current']);

        // A worktree checked out at a commit rather than a branch still needs a
        // name, and the directory it lives in is the one the person chose.
        return Environment::named($branch !== '' ? $branch : basename($own));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $path, array $arguments): string
    {
        // `-C` rather than changing this process's directory: a tool that
        // chdir's has changed something for everything that runs after it.
        $result = $this->runner->run(['git', '-C', $path, ...$arguments], timeout: 10);

        return $result->successful() ? trim($result->text()) : '';
    }
}
