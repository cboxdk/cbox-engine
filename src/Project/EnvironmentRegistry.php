<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\DeployedEnvironment;

/**
 * Every environment on this cluster, and whether the worktree that made it is
 * still there.
 *
 * THE CLUSTER IS ASKED, as everywhere else here. A list this tool kept would
 * drift from the truth the first time anybody used kubectl, and then the command
 * that deletes things would be working from a fiction.
 *
 * ORPHANED IS A FACT ABOUT THIS MACHINE, not a state on the cluster. A worktree
 * is a directory; the question "is it still there" has an answer at the moment
 * somebody asks it and no meaningful answer stored anywhere.
 */
class EnvironmentRegistry
{
    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * @return list<DeployedEnvironment>
     */
    public function all(): array
    {
        $records = $this->kubernetes->list('configmap', 'platform.cbox.dk/managed=true');

        $environments = [];

        foreach ($records as $record) {
            if ($record->name() !== ProjectDeployer::ORIGIN) {
                continue;
            }

            $project = $record->stringAt('data', 'project');
            $worktree = $record->stringAt('data', 'worktree');

            if ($project === '' || $worktree === '') {
                continue;
            }

            $environments[] = new DeployedEnvironment(
                project: $project,
                environment: $record->stringAt('data', 'environment'),
                namespace: $record->stringAt('metadata', 'namespace'),
                worktree: $worktree,
                // `is_dir` and not `git worktree list`: the repository this
                // environment came from may itself be gone, and asking git about
                // a directory that does not exist answers nothing useful.
                present: is_dir($worktree),
            );
        }

        usort(
            $environments,
            static fn (DeployedEnvironment $a, DeployedEnvironment $b): int => strcmp($a->name(), $b->name()),
        );

        return $environments;
    }
}
