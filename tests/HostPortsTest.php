<?php

declare(strict_types=1);

use Cbox\Engine\Host\MacResolver;
use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Kind\PublishedPorts;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;

/*
 * Which ports on this machine the cluster answers on.
 *
 * `https://demo.cbox.test:18443` is not the environment anybody's application
 * will run in: OAuth redirect URIs, cookie domains, CORS origins, APP_URL and
 * every link in an email carry the port. 80 and 443 are the point of a hostname.
 */

it('leaves the port out of an address when it does not need one', function (): void {
    expect(HostPorts::privileged()->url('demo.cbox.test'))->toBe('https://demo.cbox.test')
        ->and(HostPorts::high()->url('demo.cbox.test'))->toBe('https://demo.cbox.test:18443')
        ->and(HostPorts::privileged()->isPrivileged())->toBeTrue()
        ->and(HostPorts::high()->isPrivileged())->toBeFalse();
});

it('takes the real ports only when all three are free', function (): void {
    // A cluster on 80 and 443 whose DNS landed on 15353 works and is confusing
    // to explain, and the resolver file would say something different depending
    // on the day it was written.
    $all = static fn (int $port): bool => true;
    $none = static fn (int $port): bool => false;
    $notDns = static fn (int $port): bool => $port !== HostPorts::PRIVILEGED_DNS;

    expect(HostPorts::preferred($all)->isPrivileged())->toBeTrue()
        ->and(HostPorts::preferred($none)->isPrivileged())->toBeFalse()
        // Herd holds 53 and nothing else — still all or none.
        ->and(HostPorts::preferred($notDns)->isPrivileged())->toBeFalse()
        ->and(HostPorts::preferred($notDns)->https)->toBe(HostPorts::HIGH_HTTPS);
});

it('publishes the chosen ports in the cluster config', function (): void {
    $path = sys_get_temp_dir().'/cbox-ports-'.getmypid().'/kind.yaml';

    $rendered = (new ClusterConfig($path, HostPorts::privileged()))->render();

    expect($rendered)->toContain("containerPort: 30443\n        hostPort: 443")
        ->and($rendered)->toContain("containerPort: 30080\n        hostPort: 80")
        ->and($rendered)->toContain("containerPort: 30053\n        hostPort: 53")
        // The node ports are pinned whatever the host side does: kind's mappings
        // are fixed when the cluster is built, and an allocated NodePort would
        // land somewhere the host cannot reach.
        ->and((new ClusterConfig($path, HostPorts::high()))->render())
        ->toContain("containerPort: 30443\n        hostPort: 18443");
});

it('reads the ports off the running container rather than deciding again', function (): void {
    // kind fixes its mappings when the cluster is BUILT, so a machine can hold
    // one created back when something else had 443. Printing what would be
    // chosen today prints an address that does not answer.
    $runner = new FakeCommandRunner;
    $runner->stage(
        ['docker', 'inspect', 'cbox-control-plane', '--format', '{{json .NetworkSettings.Ports}}'],
        new CommandResult(ran: true, exitCode: 0, errorOutput: '', output: (string) json_encode([
            '30080/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '18080']],
            '30443/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '18443']],
        ])),
    );

    expect((new PublishedPorts($runner))->current()->https)->toBe(18443);
});

it('reports the real ports when that is what the cluster took', function (): void {
    $runner = new FakeCommandRunner;
    $runner->stage(
        ['docker', 'inspect', 'cbox-control-plane', '--format', '{{json .NetworkSettings.Ports}}'],
        new CommandResult(ran: true, exitCode: 0, errorOutput: '', output: (string) json_encode([
            '30443/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '443']],
        ])),
    );

    expect((new PublishedPorts($runner))->current()->isPrivileged())->toBeTrue();
});

it('answers what a cluster would be given when there is no cluster', function (): void {
    // Which is the right answer to "what will my address be" before `cbox up`.
    // Nothing is staged, so the inspect comes back as a command that never ran.
    $ports = (new PublishedPorts(new FakeCommandRunner))->current();

    expect($ports->https)->toBeIn([HostPorts::PRIVILEGED_HTTPS, HostPorts::HIGH_HTTPS]);
});

it('names the DNS port in the resolver file even when it is the default', function (): void {
    // Naming it costs nothing and means the file says what it does, rather than
    // relying on a default that depends on which ports were free the day the
    // cluster was built.
    $directory = sys_get_temp_dir().'/cbox-resolver-'.getmypid();

    expect((new MacResolver(HostPorts::privileged(), $directory))->desired())->toContain("port 53\n")
        ->and((new MacResolver(HostPorts::high(), $directory))->desired())->toContain("port 15353\n");
});
