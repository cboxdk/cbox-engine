<?php

declare(strict_types=1);

namespace Cbox\Engine\Kind;

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Enums\ClusterPhase;
use Cbox\Engine\ValueObjects\ClusterState;

/**
 * The local cluster, as kind builds it.
 *
 * STOPPED IS NOT ABSENT, and kind does not help with the difference: it can list
 * clusters and it can delete them, but it has nothing that reports whether one is
 * actually up, and no `start`. A kind cluster IS a container, so that question is
 * asked of the container runtime and the answer is a state of its own — a stopped
 * cluster keeps every volume, image and object, and starting it is seconds where
 * creating it is minutes.
 *
 * The consequence for the product is the whole reason this class distinguishes
 * them: `cbox down` must stop rather than destroy, or a developer loses a
 * database to a command that sounds like the opposite of `up`.
 */
class KindCluster implements ClusterManager
{
    /**
     * One cluster, and its name is not configurable.
     *
     * A second one would be a second control plane on the same laptop for no
     * gain — projects are namespaces here, exactly as tenants are namespaces on
     * a cell.
     */
    public const NAME = 'cbox';

    /** What kind calls the container it runs the cluster in. */
    /**
     * The container the cluster is.
     *
     * Public because it is an address, not an implementation detail: reading the
     * ports the cluster is published on means asking Docker about this exact
     * container, and a second copy of the name somewhere else is a second thing
     * to keep in step.
     */
    public const NODE = self::NAME.'-control-plane';

    /** kubectl's name for it, which kind derives and does not let you choose. */
    public const CONTEXT = 'kind-'.self::NAME;

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ClusterConfig $config,
        /** How many times to ask whether it is serving before giving up. */
        private readonly int $readinessAttempts = 60,
        /** Microseconds between those asks. Zero in tests, a second in life. */
        private readonly int $readinessDelay = 1_000_000,
    ) {}

    public function up(): ClusterState
    {
        $phase = $this->phase();

        if ($phase === ClusterPhase::Running) {
            return new ClusterState(self::NAME, ClusterPhase::Running, changed: false, context: self::CONTEXT);
        }

        if ($phase === ClusterPhase::Stopped) {
            // Starting the container is enough: kubelet, etcd and the API server
            // are inside it and come back with it.
            $started = $this->runner->run(['docker', 'start', self::NODE], timeout: 120);

            if (! $started->successful()) {
                return $this->failed('The cluster exists but would not start.', $started->errorOutput);
            }

            if (! $this->waitUntilServing()) {
                return $this->failed(
                    'The cluster started but its API server did not begin serving.',
                    'Try again, or `cbox destroy` and rebuild if it persists.',
                );
            }

            return new ClusterState(self::NAME, ClusterPhase::Running, changed: true, context: self::CONTEXT);
        }

        $path = $this->config->write();

        // Minutes, not seconds, on a cold image cache. A timeout shorter than
        // the work turns a slow first run into a failure that leaves half a
        // cluster behind.
        $created = $this->runner->run(
            ['kind', 'create', 'cluster', '--name', self::NAME, '--config', $path, '--wait', '60s'],
            timeout: 900,
        );

        if (! $created->successful()) {
            return $this->failed('The cluster could not be created.', $created->errorOutput);
        }

        if (! $this->waitUntilServing()) {
            return $this->failed(
                'The cluster was created but its API server did not begin serving.',
                'Try `cbox destroy` and build it again.',
            );
        }

        return new ClusterState(self::NAME, ClusterPhase::Running, changed: true, context: self::CONTEXT);
    }

    public function down(): ClusterState
    {
        if ($this->phase() !== ClusterPhase::Running) {
            return new ClusterState(self::NAME, $this->phase(), changed: false);
        }

        $stopped = $this->runner->run(['docker', 'stop', self::NODE], timeout: 120);

        if (! $stopped->successful()) {
            return $this->failed('The cluster would not stop.', $stopped->errorOutput);
        }

        return new ClusterState(self::NAME, ClusterPhase::Stopped, changed: true);
    }

    public function destroy(): ClusterState
    {
        if ($this->phase() === ClusterPhase::Absent) {
            return new ClusterState(self::NAME, ClusterPhase::Absent, changed: false);
        }

        $deleted = $this->runner->run(['kind', 'delete', 'cluster', '--name', self::NAME], timeout: 300);

        if (! $deleted->successful()) {
            return $this->failed('The cluster could not be deleted.', $deleted->errorOutput);
        }

        return new ClusterState(self::NAME, ClusterPhase::Absent, changed: true);
    }

    public function state(): ClusterState
    {
        $phase = $this->phase();

        return new ClusterState(
            self::NAME,
            $phase,
            changed: false,
            context: $phase === ClusterPhase::Running ? self::CONTEXT : '',
            failure: $this->unknownBecause,
        );
    }

    /**
     * Why the last {@see phase()} could not tell, when the reason is knowable.
     *
     * Set on every call, so it never describes an older one.
     */
    private string $unknownBecause = '';

    private function phase(): ClusterPhase
    {
        $this->unknownBecause = '';

        $clusters = $this->runner->run(['kind', 'get', 'clusters'], timeout: 30);

        if (! $clusters->ran) {
            // THE PROCESS NEVER STARTED, which is a different fact from "kind
            // ran and said no", and it has a different answer. Measured: from a
            // directory that has been deleted — the exact situation `cbox prune`
            // exists for — every child process fails to start, and the runner
            // says precisely why ("The provided cwd ... does not exist"). That
            // was thrown away and reported as a stopped container runtime,
            // sending somebody to restart something that was never the problem.
            $this->unknownBecause = $clusters->failure;

            return ClusterPhase::Unknown;
        }

        // A FAILED LISTING IS NOT AN EMPTY ONE, and only the exit code tells them
        // apart. With the container runtime stopped, `kind get clusters` runs, exits
        // 1, and prints its complaint to stderr — so reading only stdout for our name
        // found nothing and called the cluster ABSENT. Both the CLI and the desktop
        // then told somebody their cluster did not exist and offered to build it, over
        // a cluster that was sitting there intact behind a runtime nobody had started.
        // `Unknown` already renders as "could not tell — is the container runtime
        // running?", which is the true answer and the useful one.
        if (! $clusters->successful()) {
            return ClusterPhase::Unknown;
        }

        // kind exits 0 with a friendly sentence when there are none, so the
        // absence of our name is the answer rather than the exit code.
        if (! in_array(self::NAME, preg_split('/\R/', $clusters->text()) ?: [], true)) {
            return ClusterPhase::Absent;
        }

        $node = $this->runner->run(
            ['docker', 'inspect', '-f', '{{.State.Running}}', self::NODE],
            timeout: 30,
        );

        if (! $node->successful()) {
            // kind knows the cluster and the runtime does not know the
            // container: somebody removed it underneath us. Absent is the honest
            // answer, and `up` will rebuild.
            return ClusterPhase::Absent;
        }

        return $node->text() === 'true' ? ClusterPhase::Running : ClusterPhase::Stopped;
    }

    /**
     * Do not return until the cluster can actually be used.
     *
     * MEASURED, on the first live run of this class. `docker start` returns when
     * the CONTAINER is running, which is well before the API server is. `up`
     * reported success and the very next `kubectl get nodes` answered
     *
     *     Error from server (Forbidden): nodes is forbidden: User
     *     "kubernetes-admin" cannot list resource "nodes"
     *
     * — the API server was answering, and RBAC had not finished loading. Every
     * command after `up` would have raced it, and the failures would have looked
     * like permission bugs rather than a cluster that was not up yet.
     *
     * Asked from INSIDE the node, with the node's own kubectl and admin
     * kubeconfig. That keeps kubectl off the list of things a developer must
     * install before this tool works, and it is the same question the developer
     * will ask a moment later.
     */
    private function waitUntilServing(): bool
    {
        for ($attempt = 0; $attempt < $this->readinessAttempts; $attempt++) {
            $serving = $this->runner->run([
                'docker', 'exec', self::NODE,
                'kubectl', '--kubeconfig', '/etc/kubernetes/admin.conf', 'get', 'nodes',
            ], timeout: 15);

            if ($serving->successful()) {
                return true;
            }

            if ($this->readinessDelay > 0) {
                usleep($this->readinessDelay);
            }
        }

        return false;
    }

    private function failed(string $what, string $detail): ClusterState
    {
        return new ClusterState(
            self::NAME,
            ClusterPhase::Unknown,
            changed: false,
            failure: trim($what.' '.trim($detail)),
        );
    }
}
