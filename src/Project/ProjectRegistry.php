<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\ProjectState;

/**
 * What is on the cluster, as the cluster knows it.
 *
 * NOT A LIST THIS TOOL KEEPS. There is no local database of projects, and there
 * should not be: a record of what was deployed drifts from what is deployed the
 * first time anybody uses kubectl, and then the tool is confidently wrong. The
 * cluster is asked.
 *
 * Found by LABEL rather than by namespace name. `cbox-` is a prefix this tool
 * chose and somebody else's namespace could share it; the managed label is what
 * this platform actually put there.
 */
class ProjectRegistry
{
    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * @return list<ProjectState>
     */
    public function all(): array
    {
        $deployments = $this->kubernetes->list('deployment', 'platform.cbox.dk/managed=true');

        /** @var array<string, array{namespace: string, wanted: int, running: int, processes: int, webWanted: int, webRunning: int}> $projects */
        $projects = [];

        foreach ($deployments as $deployment) {
            $labels = $deployment->labels();
            $name = $labels['platform.cbox.dk/service'] ?? '';

            // FROM THE SELECTOR, not the metadata labels. The compiler puts
            // `process` on a WORKER's labels and on every Deployment's
            // SELECTOR, and on the web Deployment's metadata it writes
            // `app.kubernetes.io/component` instead. Reading the metadata label
            // therefore found no web process anywhere: every project counted its
            // web pod as a worker, and a running project was reported as idle.
            //
            // Found on the live cluster, against a fixture that had been written
            // to match this reader rather than the cluster.
            $process = $deployment->stringAt('spec', 'selector', 'matchLabels', 'platform.cbox.dk/process');

            if ($process === '') {
                $process = $labels['platform.cbox.dk/process'] ?? '';
            }

            if ($name === '') {
                continue;
            }

            $projects[$name] ??= [
                'namespace' => $deployment->stringAt('metadata', 'namespace'),
                'wanted' => 0,
                'running' => 0,
                'processes' => 0,
                'webWanted' => 0,
                'webRunning' => 0,
            ];

            $projects[$name]['wanted'] += $deployment->intAt('spec', 'replicas');
            $projects[$name]['running'] += $deployment->intAt('status', 'readyReplicas');

            // Counted so "asleep" can be told from "this project only has
            // workers": a project whose web process is at zero and whose worker
            // is running is not asleep, it is scaled to zero.
            if ($process !== 'web') {
                $projects[$name]['processes']++;
            } else {
                // The web process on its own, because scale-to-zero puts THAT
                // away and nothing else — and a worker that is down is a fault,
                // not idleness.
                $projects[$name]['webWanted'] += $deployment->intAt('spec', 'replicas');
                $projects[$name]['webRunning'] += $deployment->intAt('status', 'readyReplicas');
            }
        }

        $states = [];

        foreach ($projects as $name => $project) {
            $states[] = new ProjectState(
                name: $name,
                namespace: $project['namespace'],
                wanted: $project['wanted'],
                running: $project['running'],
                otherProcesses: $project['processes'],
                webWanted: $project['webWanted'],
                webRunning: $project['webRunning'],
            );
        }

        usort($states, static fn (ProjectState $a, ProjectState $b): int => strcmp($a->name, $b->name));

        return $states;
    }

    /**
     * Every hostname the cluster is currently routing, by project.
     *
     * FROM THE ROUTES, not from the manifests on this machine. A project's
     * `cbox.yaml` says what it would be deployed with; the route says what it
     * WAS deployed with, and the certificate has to cover the second. A file
     * edited since the last deploy would otherwise take a hostname off the
     * certificate that is still being served.
     *
     * @return array<string, list<string>>
     */
    public function hostnames(): array
    {
        $routes = $this->kubernetes->list('httproute', 'platform.cbox.dk/managed=true');

        $hostnames = [];

        foreach ($routes as $route) {
            $name = $route->labels()['platform.cbox.dk/service'] ?? '';

            if ($name === '') {
                continue;
            }

            foreach ($route->stringsAt('spec', 'hostnames') as $hostname) {
                if (! in_array($hostname, $hostnames[$name] ?? [], true)) {
                    $hostnames[$name][] = $hostname;
                }
            }

            $hostnames[$name] ??= [];
        }

        return $hostnames;
    }
}
