<?php

declare(strict_types=1);

use Cbox\Engine\Docker\DockerRuntime;
use Cbox\Engine\Doctor\Doctor;
use Cbox\Engine\Enums\Severity;
use Cbox\Engine\Host\MacResolver;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;
use Cbox\Engine\ValueObjects\Finding;

/*
 * The first command anybody types, and the only one that can explain why the
 * rest do not work.
 */

/**
 * @return list<string>
 */
function docker(): array
{
    return ['docker', 'info', '--format', '{{.OperatingSystem}}|{{.Architecture}}|{{.ServerVersion}}'];
}

function doctorWith(FakeCommandRunner $runner, bool $resolves = true): Doctor
{
    // A resolver directory in a temporary place, written or not. A test that
    // needed /etc/resolver would be a test nobody runs.
    $directory = sys_get_temp_dir().'/cbox-resolver-'.getmypid().'-'.($resolves ? 'ok' : 'no');
    @mkdir($directory, 0755, true);

    $resolver = new MacResolver(HostPorts::high(), $directory);

    if ($resolves) {
        file_put_contents($resolver->path(), $resolver->desired());
    } else {
        @unlink($resolver->path());
    }

    return new Doctor(new DockerRuntime($runner), $resolver);
}

it('asks the SERVER, because a client alone answers when nothing is running', function (): void {
    $runner = new FakeCommandRunner;
    doctorWith($runner)->examine();

    // `docker version` answers from the client with no daemon, so a stopped
    // runtime would look installed and healthy. `docker info` needs the server.
    expect($runner->wasRun(docker()))->toBeTrue();
});

it('tells a missing runtime apart from a stopped one', function (): void {
    // Nothing staged: the binary is not there at all.
    $missing = doctorWith(new FakeCommandRunner)->examine();

    expect($missing[0]->severity)->toBe(Severity::Blocked)
        ->and($missing[0]->detail)->toContain('No container runtime')
        ->and($missing[0]->remedy)->toContain('Install');

    // Installed, and the daemon is not listening. A different sentence, because
    // it is a different action: opening something already on the machine.
    $stopped = doctorWith((new FakeCommandRunner)->stage(docker(), new CommandResult(
        ran: true, exitCode: 1, output: '',
        errorOutput: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.',
    )))->examine();

    expect($stopped[0]->severity)->toBe(Severity::Blocked)
        ->and($stopped[0]->detail)->toContain('is not running')
        ->and($stopped[0]->detail)->toContain('Cannot connect to the Docker daemon')
        ->and($stopped[0]->remedy)->toContain('Start it');
});

it('names the runtime that is actually running', function (): void {
    // MEASURED on this machine: OrbStack reports itself in OperatingSystem.
    // Saying "OrbStack is running" beats "Docker is running" for somebody who
    // has never installed Docker Desktop.
    $findings = doctorWith((new FakeCommandRunner)->stage(docker(), new CommandResult(
        ran: true, exitCode: 0, output: "OrbStack|aarch64|29.4.0\n", errorOutput: '',
    )))->examine();

    expect($findings[0]->severity)->toBe(Severity::Ok)
        ->and($findings[0]->detail)->toContain('OrbStack 29.4.0');
});

it('does not warn about an architecture the images are built for', function (): void {
    $doctor = doctorWith((new FakeCommandRunner)->stage(docker(), new CommandResult(
        ran: true, exitCode: 0, output: 'OrbStack|aarch64|29.4.0', errorOutput: '',
    )));

    $findings = $doctor->examine();
    $architecture = $findings[1];

    // THIS USED TO BE A WARNING, and it was right to be: the Cbox base images
    // were published for amd64 only, so the production image ran under
    // emulation. They are built natively for arm64 now, and a warning that
    // outlived its cause tells somebody their machine is the problem long after
    // it stopped being one.
    expect($architecture->severity)->toBe(Severity::Ok)
        ->and($architecture->detail)->toContain('natively')
        ->and($doctor->verdict($findings))->not->toBe(Severity::Blocked);
});

it('is clean on amd64', function (): void {
    $doctor = doctorWith((new FakeCommandRunner)->stage(docker(), new CommandResult(
        ran: true, exitCode: 0, output: 'Docker Desktop|x86_64|29.4.0', errorOutput: '',
    )));

    expect($doctor->verdict($doctor->examine()))->toBe(Severity::Ok);
});

it('does not ask about architecture when nothing is running', function (): void {
    // Inferring the rest from a machine that cannot answer produces findings
    // that read as measurements. There is one answer worth giving here.
    expect(doctorWith(new FakeCommandRunner)->examine())->toHaveCount(1);
});

it('says why a project opens in curl and not in a browser', function (): void {
    // The most confusing failure this product has: everything else perfect —
    // cluster up, gateway serving, certificate valid — and the developer still
    // gets nothing, because their machine has never been told where to ask about
    // the domain. `curl --resolve` works, which makes it worse: the first thing
    // they try in order to debug it succeeds.
    $doctor = doctorWith((new FakeCommandRunner)->stage(docker(), new CommandResult(
        ran: true, exitCode: 0, output: 'OrbStack|x86_64|29.4.0', errorOutput: '',
    )), resolves: false);

    $findings = $doctor->examine();
    $hostnames = collect($findings)->firstWhere('subject', 'Hostnames');

    expect($hostnames)->toBeInstanceOf(Finding::class);
    assert($hostnames instanceof Finding);

    expect($hostnames->severity)->toBe(Severity::Warning)
        // What somebody TYPES, not the command's internal name. The remedy is
        // prose in front of a person; `local:setup` is how the registry spells
        // it, and printing that would be the tool leaking its own plumbing.
        ->and($hostnames->remedy)->toContain('cbox setup')
        // A warning, not a block: everything except opening a browser works
        // without it, and refusing to bring a cluster up over a resolver file
        // would be the tool deciding what somebody may do next.
        ->and($doctor->verdict($findings)->stopsEverything())->toBeFalse();
});

it('says nothing about hostnames when the machine already resolves them', function (): void {
    $doctor = doctorWith((new FakeCommandRunner)->stage(docker(), new CommandResult(
        ran: true, exitCode: 0, output: 'Docker Desktop|x86_64|29.4.0', errorOutput: '',
    )), resolves: true);

    expect($doctor->verdict($doctor->examine()))->toBe(Severity::Ok);
});
