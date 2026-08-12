<?php

declare(strict_types=1);

use Cbox\Engine\Project\OrphanedObjects;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * @param  array<string, string>  $labels
 */
function object(string $kind, string $name, string $namespace = 'cbox-demo'): ManifestDocument
{
    return ManifestDocument::fromArray([
        'apiVersion' => 'v1',
        'kind' => $kind,
        'metadata' => [
            'name' => $name,
            'namespace' => $namespace,
            'labels' => ['platform.cbox.dk/managed' => 'true'],
        ],
    ]);
}

it('removes what the manifest stopped asking for', function (): void {
    // THE OUTAGE THIS EXISTS FOR, measured on the local cluster. A project with
    // `scale_to_zero: true` was changed to `replicas: 2`. The apply was correct
    // and two pods went ready; nine seconds later the HTTPScaledObject nobody
    // had removed took the Deployment back to zero and held it, and the route
    // no longer pointed at the interceptor that could wake it. Unreachable,
    // unwakeable, and freshly deployed with a green tick.
    $cluster = new RecordingKubernetes;
    $cluster->listed = [
        object('Deployment', 'demo'),
        object('HTTPScaledObject', 'demo'),
    ];

    $swept = new OrphanedObjects($cluster)->sweep('cbox-demo', [object('Deployment', 'demo')]);

    expect($swept->removed)->toBe(['HTTPScaledObject/demo'])
        ->and($cluster->deleted)->toBe(['httpscaledobject/demo']);
});

it('leaves everything the manifest still asks for', function (): void {
    $cluster = new RecordingKubernetes;
    $cluster->listed = [object('Deployment', 'demo'), object('Service', 'demo')];

    $swept = new OrphanedObjects($cluster)
        ->sweep('cbox-demo', [object('Deployment', 'demo'), object('Service', 'demo')]);

    expect($swept->isEmpty())->toBeTrue()
        ->and($cluster->deleted)->toBe([]);
});

it('never deletes something that holds data, and says so', function (): void {
    // Dropping a line from a YAML file is not consent to destroy a database.
    $cluster = new RecordingKubernetes;
    $cluster->listed = [object('Deployment', 'demo'), object('StatefulSet', 'cache'), object('Cluster', 'maindb')];

    $swept = new OrphanedObjects($cluster)->sweep('cbox-demo', [object('Deployment', 'demo')]);

    expect($cluster->deleted)->toBe([])
        ->and($swept->retained)->toBe(['StatefulSet/cache', 'Cluster/maindb'])
        ->and($swept->removed)->toBe([]);
});

it('keeps the service that reaches a database it kept', function (): void {
    // Half-dismantling a retained cache — its StatefulSet running and the
    // Service that addresses it deleted — is worse than either whole answer.
    $cluster = new RecordingKubernetes;
    $cluster->listed = [object('Deployment', 'demo'), object('StatefulSet', 'cache'), object('Service', 'cache')];

    $swept = new OrphanedObjects($cluster)->sweep('cbox-demo', [object('Deployment', 'demo')]);

    expect($cluster->deleted)->toBe([])
        ->and($swept->retained)->toBe(['StatefulSet/cache']);
});

it('cannot reach another environment', function (): void {
    // One namespace per environment is the whole of the isolation, so a sweep
    // that read across namespaces would delete a sibling branch's objects.
    $cluster = new RecordingKubernetes;
    $cluster->listed = [object('Deployment', 'demo', 'cbox-demo-feature')];

    $swept = new OrphanedObjects($cluster)->sweep('cbox-demo', [object('Deployment', 'demo')]);

    expect($cluster->deleted)->toBe([])
        ->and($swept->isEmpty())->toBeTrue();
});

it('does not touch the pods and replica sets that inherit the label', function (): void {
    // A pod template's labels are copied onto every Pod and ReplicaSet, so a
    // sweep of "everything carrying our label" would delete the running pods of
    // a healthy project and report it as housekeeping.
    $cluster = new RecordingKubernetes;
    $cluster->listed = [object('Deployment', 'demo'), object('Pod', 'demo-5849977ffd-mq9wb'), object('ReplicaSet', 'demo-5849977ffd')];

    new OrphanedObjects($cluster)->sweep('cbox-demo', [object('Deployment', 'demo')]);

    expect($cluster->deleted)->toBe([]);
});

it('asks for nothing the cluster does not serve', function (): void {
    // KEDA, cert-manager and the Gateway API are addons. On a machine without
    // one, listing its kind is an error rather than an empty answer.
    $cluster = new class extends RecordingKubernetes
    {
        /** @var list<string> */
        public array $asked = [];

        public function serves(string $apiVersion, string $kind): bool
        {
            return $kind === 'Deployment';
        }

        public function list(string $kind, string $selector, string $namespace = ''): array
        {
            $this->asked[] = $kind;

            return parent::list($kind, $selector, $namespace);
        }
    };

    new OrphanedObjects($cluster)->sweep('cbox-demo', [object('Deployment', 'demo')]);

    expect($cluster->asked)->toBe(['deployment']);
});

it('refuses to subtract against a set that compiled to nothing', function (): void {
    // An empty desired state is not "the project asks for nothing" — it is a
    // manifest that failed to read or a compiler that returned nothing, and
    // acting on it would delete the whole project.
    $cluster = new RecordingKubernetes;
    $cluster->listed = [object('Deployment', 'demo'), object('Service', 'demo')];

    $swept = new OrphanedObjects($cluster)->sweep('cbox-demo', []);

    expect($cluster->deleted)->toBe([])
        ->and($swept->isEmpty())->toBeTrue();
});
