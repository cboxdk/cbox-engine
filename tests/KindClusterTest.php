<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Enums\ClusterPhase;
use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\Kind\KindCluster;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Tests\RecordingCluster;
use Cbox\Engine\ValueObjects\CommandResult;
use Illuminate\Support\Facades\Artisan;

/*
 * kind can list clusters and delete them. It cannot tell you whether one is
 * actually up, and it has no `start` — so the difference between stopped and
 * absent is asked of the container runtime, and getting it wrong costs a
 * developer their databases.
 */

function ok(string $output = ''): CommandResult
{
    return new CommandResult(ran: true, exitCode: 0, output: $output, errorOutput: '');
}

function failing(string $error): CommandResult
{
    return new CommandResult(ran: true, exitCode: 1, output: '', errorOutput: $error);
}

function clusterWith(FakeCommandRunner $runner, int $attempts = 3): KindCluster
{
    // No delay between readiness asks: the wait is real in life and must not be
    // real in a test.
    return new KindCluster(
        $runner,
        new ClusterConfig(sys_get_temp_dir().'/cbox-test-kind.yaml'),
        readinessAttempts: $attempts,
        readinessDelay: 0,
    );
}

/**
 * @return list<string>
 */
function serving(): array
{
    return ['docker', 'exec', 'cbox-control-plane', 'kubectl', '--kubeconfig', '/etc/kubernetes/admin.conf', 'get', 'nodes'];
}

it('tells a stopped cluster apart from one that does not exist', function (): void {
    $stopped = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok("cbox\n"))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok("false\n"));

    expect(clusterWith($stopped)->state()->phase)->toBe(ClusterPhase::Stopped);

    // kind lists something else entirely — a cluster from another project.
    $absent = (new FakeCommandRunner)->stage(['kind', 'get', 'clusters'], ok("cortex-cell-dev\n"));

    expect(clusterWith($absent)->state()->phase)->toBe(ClusterPhase::Absent);
});

it('starts a stopped cluster instead of rebuilding it', function (): void {
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok('false'))
        ->stage(['docker', 'start', 'cbox-control-plane'], ok())
        ->stage(serving(), ok('cbox-control-plane   Ready'));

    $state = clusterWith($runner)->up();

    expect($state->phase)->toBe(ClusterPhase::Running)
        ->and($state->changed)->toBeTrue();

    // Rebuilding would be minutes instead of seconds, and would take everything
    // in the cluster with it.
    expect(collect($runner->calls)->contains(fn (array $c): bool => in_array('create', $c, true)))->toBeFalse();
});

it('does nothing, and says so, when the cluster is already up', function (): void {
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok('true'));

    $state = clusterWith($runner)->up();

    expect($state->running())->toBeTrue()
        // After a three-minute wait, "already up" is the only part worth reading.
        ->and($state->changed)->toBeFalse()
        ->and($runner->wasRun(['docker', 'start', 'cbox-control-plane']))->toBeFalse();
});

it('creates the cluster from a config when there is none', function (): void {
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok(''))
        ->stage([
            'kind', 'create', 'cluster', '--name', 'cbox',
            '--config', sys_get_temp_dir().'/cbox-test-kind.yaml', '--wait', '60s',
        ], ok())
        ->stage(serving(), ok('cbox-control-plane   Ready'));

    expect(clusterWith($runner)->up()->running())->toBeTrue();
});

it('STOPS on down, and never deletes', function (): void {
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok('true'))
        ->stage(['docker', 'stop', 'cbox-control-plane'], ok());

    $state = clusterWith($runner)->down();

    expect($state->phase)->toBe(ClusterPhase::Stopped);

    // The most expensive mistake this tool could offer, offered to somebody in
    // a hurry. `down` keeps the volumes; destroying is its own command.
    expect(collect($runner->calls)->contains(fn (array $c): bool => in_array('delete', $c, true)))->toBeFalse();
});

it('treats a cluster kind knows and the runtime lost as absent', function (): void {
    // Somebody removed the container underneath us. Absent is honest, and `up`
    // rebuilds — reporting it as stopped would make `up` try to start a
    // container that is not there.
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], failing('No such object'));

    expect(clusterWith($runner)->state()->phase)->toBe(ClusterPhase::Absent);
});

it('reports a runtime that is not answering as unknown, not as absent', function (): void {
    // Nothing staged: `kind` itself could not be run. Answering "absent" would
    // send `up` off to build a cluster on a machine with no container runtime.
    expect(clusterWith(new FakeCommandRunner)->state()->phase)->toBe(ClusterPhase::Unknown);
});

it('publishes the gateway on unprivileged ports, so it can coexist', function (): void {
    $config = (new ClusterConfig(sys_get_temp_dir().'/cbox-test-kind.yaml'))->render();

    // 80 and 443 on the host belong to whatever the developer already runs
    // there, Herd included. A front proxy owns those and routes by hostname.
    expect($config)->toContain('hostPort: 18080')
        ->and($config)->toContain('hostPort: 18443')
        // And the label without which a gateway schedules and answers nothing.
        ->and($config)->toContain('ingress-ready=true');
});

it('does not report a cluster up until it actually serves', function (): void {
    // MEASURED on the first live run: `docker start` returns when the CONTAINER
    // is running, well before the API server is. `up` reported success and the
    // next kubectl call answered "nodes is forbidden ... cannot list resource" —
    // the API server was answering and RBAC had not finished loading. Every
    // command after `up` would have raced it, and the failures would have looked
    // like permission bugs rather than a cluster that was not ready.
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok('false'))
        ->stage(['docker', 'start', 'cbox-control-plane'], ok())
        ->stage(serving(), failing('nodes is forbidden: User "kubernetes-admin" cannot list resource "nodes"'));

    $state = clusterWith($runner)->up();

    expect($state->running())->toBeFalse()
        ->and($state->failure)->toContain('did not begin serving');
});

it('asks the node itself, so kubectl is not something you must install first', function (): void {
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok('false'))
        ->stage(['docker', 'start', 'cbox-control-plane'], ok())
        ->stage(serving(), ok('Ready'));

    clusterWith($runner)->up();

    expect($runner->wasRun(serving()))->toBeTrue();
});

it('destroys only when asked, and only through its own command', function (): void {
    $runner = (new FakeCommandRunner)
        ->stage(['kind', 'get', 'clusters'], ok('cbox'))
        ->stage(['docker', 'inspect', '-f', '{{.State.Running}}', 'cbox-control-plane'], ok('true'))
        ->stage(['kind', 'delete', 'cluster', '--name', 'cbox'], ok());

    expect(clusterWith($runner)->destroy()->phase)->toBe(ClusterPhase::Absent);
});

it('refuses to destroy with nobody there to ask', function (): void {
    // An agent, a script and CI cannot answer a prompt. Assuming consent that
    // could not be asked for is how a developer loses a cluster to a `-n` flag
    // somebody added for an unrelated reason.
    //
    // AGAINST A FAKE, because the question is what the command does when a
    // cluster EXISTS — and reading the real one made this pass only while the
    // developer happened to have one. It failed the first time a cluster was
    // destroyed on this machine, which is not what a test of consent should be
    // measuring.
    app()->instance(ClusterManager::class, new RecordingCluster);

    $exit = Artisan::call('local:destroy', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Refusing to destroy without --force');
});

it('cannot tell what it did not manage to ask', function (): void {
    // With the container runtime stopped, `kind get clusters` RUNS, exits 1, and
    // writes its complaint to stderr. Reading only stdout for our name found
    // nothing and reported the cluster ABSENT — so the CLI offered to build a
    // cluster that already existed, and the desktop window showed the same. The
    // exit code is the only thing that separates "no clusters" from "could not ask".
    $runner = (new FakeCommandRunner)->stage(
        ['kind', 'get', 'clusters'],
        new CommandResult(true, 1, '', 'ERROR: failed to list clusters: docker not running'),
    );

    $cluster = new KindCluster($runner, new ClusterConfig(sys_get_temp_dir().'/kind.yaml'));

    expect($cluster->state()->phase)->toBe(ClusterPhase::Unknown);
});

it('does not blame the container runtime for a process that never started', function (): void {
    // MEASURED FROM A DELETED DIRECTORY, which is the exact situation `cbox
    // prune` exists for: every child process fails to start, and the runner says
    // precisely why. That was discarded and reported as a stopped runtime,
    // sending somebody to restart something that was never the problem.
    $runner = new FakeCommandRunner;

    $state = (new KindCluster($runner, new ClusterConfig(sys_get_temp_dir().'/kind.yaml')))->state();

    expect($state->phase)->toBe(ClusterPhase::Unknown)
        ->and($state->failure)->toContain('nothing staged');
});

it('still asks about the runtime when kind ran and failed', function (): void {
    // kind runs, exits 1 and complains to stderr when the runtime is stopped.
    // There the question is the true answer and the useful one.
    $runner = (new FakeCommandRunner)->stage(
        ['kind', 'get', 'clusters'],
        new CommandResult(true, 1, '', 'cannot connect to the Docker daemon'),
    );

    $state = (new KindCluster($runner, new ClusterConfig(sys_get_temp_dir().'/kind.yaml')))->state();

    expect($state->phase)->toBe(ClusterPhase::Unknown)
        ->and($state->failure)->toBe('');
});
