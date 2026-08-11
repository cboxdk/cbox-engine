<?php

declare(strict_types=1);

use Cbox\Engine\Host\MacResolver;
use Cbox\Engine\Kind\HostPorts;

/*
 * The one privileged act in this product, and the reason it is only one: macOS
 * resolvers take a `port` directive, so the nameserver lives on an unprivileged
 * port inside the cluster and nothing here binds 53 or stays running as root.
 */

function resolverIn(string $suffix): MacResolver
{
    $directory = sys_get_temp_dir().'/cbox-res-'.getmypid().'-'.$suffix;
    @mkdir($directory, 0755, true);

    return new MacResolver(HostPorts::high(), $directory);
}

it('points at the cluster on the port the cluster publishes', function (): void {
    $resolver = resolverIn('desired');

    expect($resolver->desired())->toContain('nameserver 127.0.0.1')
        ->and($resolver->desired())->toContain('port 15353')
        // A file in /etc with no explanation is one nobody dares delete years
        // later.
        ->and($resolver->desired())->toContain('Written by Cbox Local');
});

it('tells missing apart from pointing somewhere else', function (): void {
    // The middle state produces the strangest symptom this product has: a
    // hostname that resolves to nothing, or to somebody else's server, while
    // every part of the platform reports itself healthy.
    $resolver = resolverIn('states');
    @unlink($resolver->path());

    expect($resolver->state()->present)->toBeFalse();

    file_put_contents($resolver->path(), "nameserver 127.0.0.1\nport 5300\n");

    expect($resolver->state()->present)->toBeTrue()
        ->and($resolver->state()->current)->toBeFalse()
        // The wrong contents, quoted back, because "it is wrong" without saying
        // how leaves somebody opening the file themselves.
        ->and($resolver->state()->found)->toContain('5300');
});

it('does not call a working file stale because a comment was reworded', function (): void {
    $resolver = resolverIn('comment');

    file_put_contents(
        $resolver->path(),
        "# something an older version wrote\n\nnameserver 127.0.0.1\nport 15353\n",
    );

    // Compared on what it MEANS. A file that works reported as wrong is a
    // password prompt somebody did not need.
    expect($resolver->state()->current)->toBeTrue();
});

it('hands over a command rather than running one it has not shown', function (): void {
    // Writing this needs a password. A tool that collects one to do something it
    // has not shown you is a tool nobody should give one to — so the command is
    // returned, and `sudo` does its own prompting.
    expect(resolverIn('command')->installCommand())
        ->toBe(['sudo', 'tee', sys_get_temp_dir().'/cbox-res-'.getmypid().'-command/cbox.test']);
});
