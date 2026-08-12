<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectRegistry;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;

/*
 * Asleep and idle both show no web pod, and telling them apart is the whole
 * point: one was put away deliberately and its database is hibernated, the other
 * answers the next request in about two seconds. Reported the same way, a
 * developer wakes what was never asleep.
 */

function deployment(string $service, string $process, int $wanted, int $running): ManifestDocument
{
    return ManifestDocument::fromArray([
        'apiVersion' => 'apps/v1',
        'kind' => 'Deployment',
        'metadata' => [
            'name' => $service.($process === 'web' ? '' : '-'.$process),
            'namespace' => 'cbox-'.$service,
            // AS THE COMPILER ACTUALLY WRITES IT. A worker carries `process` in
            // its labels; the web Deployment does not — it carries
            // `app.kubernetes.io/component` — and only the SELECTOR has
            // `process` on both. A fixture that put the label on everything is
            // what let the reader look in the wrong place for months.
            'labels' => array_filter([
                'platform.cbox.dk/managed' => 'true',
                'platform.cbox.dk/service' => $service,
                'platform.cbox.dk/process' => $process === 'web' ? null : $process,
                'app.kubernetes.io/component' => $process === 'web' ? 'web' : null,
            ]),
        ],
        'spec' => [
            'replicas' => $wanted,
            'selector' => ['matchLabels' => [
                'platform.cbox.dk/service' => $service,
                'platform.cbox.dk/process' => $process,
            ]],
        ],
        'status' => ['readyReplicas' => $running],
    ]);
}

function registryWith(ManifestDocument ...$deployments): ProjectRegistry
{
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = array_values($deployments);

    return new ProjectRegistry($kubernetes);
}

it('calls a project asleep only when everything is at zero', function (): void {
    // Its workers too. A project whose web is at zero and whose worker is still
    // running was not put away — it is idle, and its database is still up.
    $asleep = registryWith(
        deployment('acme', 'web', 0, 0),
        deployment('acme', 'queue', 0, 0),
    )->all();

    expect($asleep[0]->asleep())->toBeTrue()
        ->and($asleep[0]->idle())->toBeFalse();
});

it('calls a project idle when its web is away and its workers are not', function (): void {
    // Scale-to-zero puts the WEB process away and nothing else. Everything the
    // project wants is running; there is simply no web pod until a request
    // arrives.
    $idle = registryWith(
        deployment('acme', 'web', 0, 0),
        deployment('acme', 'queue', 1, 1),
    )->all();

    expect($idle[0]->asleep())->toBeFalse()
        ->and($idle[0]->idle())->toBeTrue()
        ->and($idle[0]->degraded())->toBeFalse()
        ->and($idle[0]->toArray()['state'])->toBe('idle');
});

it('does not call a broken project idle', function (): void {
    // The sentence "idle, wakes on the next request" in front of a queue worker
    // in CrashLoopBackOff would cost somebody an hour. Found on the live
    // cluster, where two real applications with failing workers both read as
    // idle.
    $broken = registryWith(
        deployment('acme', 'web', 1, 1),
        deployment('acme', 'queue', 1, 0),
    )->all();

    expect($broken[0]->idle())->toBeFalse()
        ->and($broken[0]->degraded())->toBeTrue()
        ->and($broken[0]->toArray()['state'])->toBe('degraded');
});

it('calls a project degraded when it wants pods it does not have', function (): void {
    $starting = registryWith(deployment('acme', 'web', 1, 0))->all();

    expect($starting[0]->degraded())->toBeTrue()
        ->and($starting[0]->toArray()['state'])->toBe('degraded');
});

it('gathers a project from every process, not one deployment each', function (): void {
    $states = registryWith(
        deployment('acme', 'web', 2, 2),
        deployment('acme', 'queue', 1, 1),
        deployment('other', 'web', 1, 1),
    )->all();

    expect($states)->toHaveCount(2)
        ->and($states[0]->name)->toBe('acme')
        ->and($states[0]->wanted)->toBe(3)
        // Sorted, so the same cluster reads the same way twice.
        ->and($states[1]->name)->toBe('other');
});

it('does not ask the cluster what is deployed when it is not running', function (): void {
    // A list of projects that reads as empty because the cluster is down is a
    // list that lies.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    // No cluster here: the fake cluster manager reports absent, and status must
    // not present "no projects" as a fact about the projects.
    expect((new ProjectRegistry($kubernetes))->all())->toBe([]);
});

it('knows which deployment is the web one', function (): void {
    // The compiler does NOT put `process` in the web Deployment's metadata
    // labels — only in its selector — so a reader looking at the labels finds
    // no web process anywhere, counts the web pod as a worker, and reports a
    // perfectly healthy project as idle. Which is what it did, on the live
    // cluster, for every project at once.
    $running = registryWith(deployment('acme', 'web', 1, 1))->all();

    expect($running[0]->webWanted)->toBe(1)
        ->and($running[0]->otherProcesses)->toBe(0)
        ->and($running[0]->idle())->toBeFalse()
        ->and($running[0]->toArray()['state'])->toBe('running');
});

function scaler(string $service): ManifestDocument
{
    return ManifestDocument::fromArray([
        'apiVersion' => 'http.keda.sh/v1alpha1',
        'kind' => 'HTTPScaledObject',
        'metadata' => [
            'name' => $service,
            'namespace' => 'cbox-'.$service,
            'labels' => [
                'platform.cbox.dk/managed' => 'true',
                'platform.cbox.dk/service' => $service,
            ],
        ],
    ]);
}

it('does not tell somebody to wake a project that wakes itself', function (): void {
    // THE COMMONEST PROJECT THERE IS, and it read as asleep. An application with
    // one process and scale-to-zero has nothing running once its web idles down,
    // so "everything is at zero" matched — and status printed `cbox wake` at a
    // project that answers the next request on its own. The distinction only
    // survived for a project that happened to own a second process.
    //
    // Measured live: deployed at zero, woken by a request in 6s, idled back
    // down, reported as put away.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [deployment('acme', 'web', 0, 0), scaler('acme')];

    $state = new ProjectRegistry($kubernetes)->all()[0];

    expect($state->asleep())->toBeFalse()
        ->and($state->idle())->toBeTrue()
        ->and($state->toArray()['state'])->toBe('idle');
});

it('still calls a project asleep once its scaler is gone', function (): void {
    // `cbox sleep` compiles a set with no scaler in it, and the deploy takes the
    // old one away — so the absence is what "put away" means on the cluster.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [deployment('acme', 'web', 0, 0)];

    $state = new ProjectRegistry($kubernetes)->all()[0];

    expect($state->asleep())->toBeTrue()
        ->and($state->toArray()['state'])->toBe('asleep');
});
