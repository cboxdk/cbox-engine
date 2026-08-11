<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Tests\RecordingCluster;
use Illuminate\Support\Facades\Artisan;

/*
 * Taking Cbox Local off the machine.
 *
 * A tool that cannot be removed is one people hesitate to install — and the
 * hesitation is earned, because a local platform touches a container runtime, a
 * resolver file in /etc, and however many gigabytes of images.
 */

it('does not treat --json as consent', function (): void {
    // MEASURED, thirty seconds after the command was written: `--json` returned
    // before the confirmation and destroyed a cluster with four applications on
    // it. A machine-readable answer is a different SHAPE of answer, never a
    // different decision — and nobody can answer a prompt through a JSON stream.
    $cluster = new RecordingCluster;
    app()->instance(ClusterManager::class, $cluster);

    $exit = Artisan::call('local:uninstall', ['--json' => true]);

    /** @var array<string, mixed>|null $document */
    $document = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($cluster->destroyed)->toBeFalse()
        ->and($document['error'] ?? null)->toContain('--force');
});

it('will not uninstall with nobody there to ask', function (): void {
    $cluster = new RecordingCluster;
    app()->instance(ClusterManager::class, $cluster);

    $exit = Artisan::call('local:uninstall', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and($cluster->destroyed)->toBeFalse();
});

it('names the file it cannot remove itself', function (): void {
    // The resolver needs administrator rights, and asking for a password after
    // the destructive part is done is asking at the worst possible moment.
    $cluster = new RecordingCluster;
    app()->instance(ClusterManager::class, $cluster);

    Artisan::call('local:uninstall', ['--force' => true, '--json' => true]);

    /** @var array<string, mixed>|null $document */
    $document = json_decode(Artisan::output(), true);

    expect($cluster->destroyed)->toBeTrue()
        ->and(data_get($document, 'resolver.command'))->toContain('sudo rm');
});
