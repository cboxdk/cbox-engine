<?php

declare(strict_types=1);

use Cbox\Engine\Project\ConnectionSourceFactory;
use Cbox\Engine\Project\ProjectManifest;
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Project\ResourceSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Database\DatabaseEngine;

/*
 * A resource is created and then BOUND, never injected. That distinction is what
 * makes "shared or scoped per project" one mechanism instead of two — and the
 * password is never carried, because a binding resolves to a secretKeyRef at a
 * Secret in the cluster.
 */

function resourceManifest(string $yaml): ProjectManifest
{
    $root = sys_get_temp_dir().'/cbox-res-'.getmypid().'-'.substr(md5($yaml), 0, 8);
    @mkdir($root, 0755, true);
    file_put_contents($root.'/cbox.yaml', $yaml);

    return (new ProjectManifestReader)->read($root.'/cbox.yaml');
}

it('takes an engine name a developer would write', function (): void {
    // `cache: valkey` is the whole of the common case, so it is allowed to be
    // the whole of what is written.
    $manifest = resourceManifest("name: acme\nimage: acme/web:1\nresources:\n  cache: valkey\n");

    expect($manifest->resources)->toHaveCount(1)
        ->and($manifest->resources[0]->engine)->toBe(DatabaseEngine::Valkey);

    // And the names people actually use for the same thing.
    expect(ResourceSpec::engineFrom('redis'))->toBe(DatabaseEngine::Valkey)
        ->and(ResourceSpec::engineFrom('mysql'))->toBe(DatabaseEngine::Percona)
        ->and(ResourceSpec::engineFrom('postgresql'))->toBe(DatabaseEngine::Postgres);
});

it('pins a version rather than taking whatever is newest', function (): void {
    // A database whose major version moves under a project between two machines
    // is the opposite of what this product is for.
    $manifest = resourceManifest("name: acme\nimage: acme/web:1\nresources:\n  db: postgres\n");

    expect($manifest->resources[0]->version)->toBe('17');
});

it('asks for one instance, because that is all the compiler will keep', function (): void {
    // More than one of an engine the platform schedules itself is refused
    // upstream: nothing configures replication, so it would be several
    // independent databases behind one Service.
    $manifest = resourceManifest("name: acme\nimage: acme/web:1\nresources:\n  db: postgres\n");

    expect($manifest->toDatabaseSpecs()[0]->instances)->toBe(1);
});

it('resolves Postgres to the names CloudNativePG chooses, not ours', function (): void {
    // CNPG generates `<cluster>-app` and publishes `<cluster>-rw`. Both are its
    // contract; getting one wrong produces an application that starts perfectly
    // and cannot connect.
    $source = (new ConnectionSourceFactory)->forResource(
        new ResourceSpec('db', DatabaseEngine::Postgres, '17', '1Gi', []),
        'cbox-acme',
    );

    expect($source->secretName)->toBe('db-app')
        ->and($source->secretKeys[ConnectionField::User->value])->toBe('username')
        ->and($source->plain[ConnectionField::Host->value])->toBe('db-rw.cbox-acme.svc.cluster.local');
});

it('gives a Valkey no password, because none is deployed', function (): void {
    // MEASURED: binding one made every workload mount a Secret nothing creates,
    // and sit in CreateContainerConfigError beside a cache that was running
    // perfectly. The compiler emits `<name>-credentials` only when a password is
    // set, and Valkey's StatefulSet never references it.
    $source = (new ConnectionSourceFactory)->forResource(
        new ResourceSpec('cache', DatabaseEngine::Valkey, '8', '1Gi', []),
        'cbox-acme',
    );

    expect($source->secretKeys)->toBe([])
        ->and($source->secretName)->toBe('')
        ->and($source->plain[ConnectionField::Port->value])->toBe('6379');

    // And it is not in the default map either, so nobody arrives at it by
    // accident.
    expect(ResourceSpec::defaultMap(DatabaseEngine::Valkey, 'cache'))->not->toHaveKey('password');
});

it('refuses a Valkey password where it is written, not where it fails', function (): void {
    expect(fn () => resourceManifest(
        "name: acme\nimage: acme/web:1\nresources:\n  cache:\n    engine: valkey\n    bind:\n      password: REDIS_PASSWORD\n"
    ))->toThrow(RuntimeException::class, 'without a password');
});

it('binds by name, so an application reads what it already looks for', function (): void {
    $manifest = resourceManifest(
        "name: acme\nimage: acme/web:1\nresources:\n  db:\n    engine: postgres\n    bind:\n      host: DB_HOST\n      password: DB_PASSWORD\n"
    );

    $sources = ['db' => (new ConnectionSourceFactory)->forResource($manifest->resources[0], $manifest->namespace())];
    $spec = $manifest->toServiceSpec($sources);

    // A platform that decides which variables an application reads has guessed
    // at a framework, and a developer whose application wants DB_HOST where the
    // platform wrote DATABASE_HOST gets one that starts and cannot connect.
    expect($spec->bindings)->toHaveCount(1)
        ->and(collect($spec->bindings[0]->map)->pluck('name')->all())->toBe(['DB_HOST', 'DB_PASSWORD']);
});

it('refuses a storage size that is not one', function (): void {
    expect(fn () => resourceManifest(
        "name: acme\nimage: acme/web:1\nresources:\n  db:\n    engine: postgres\n    storage: loads\n"
    ))->toThrow(RuntimeException::class, 'size like');
});

it('refuses to scale to zero with nothing that could wake it', function (): void {
    // The wake is a request arriving: an interceptor holds it open while the pod
    // starts. A project nothing routes to has nothing to wake it, and the
    // compiler quietly emits the ordinary shape instead — so the setting would
    // be there, and do nothing, and nobody would know.
    expect(fn () => resourceManifest(
        "name: acme\nimage: acme/web:1\ndomains: []\nscale_to_zero: true\n"
    ))->toThrow(RuntimeException::class, 'nothing could ever wake it');
});

it('carries the idle period through to the autoscaler', function (): void {
    $manifest = resourceManifest("name: acme\nimage: acme/web:1\nscale_to_zero: true\nidle_seconds: 45\n");

    expect($manifest->toServiceSpec()->scaleToZero)->toBeTrue()
        ->and($manifest->toServiceSpec()->idleTimeoutSeconds)->toBe(45);
});

it('refuses an idle period too short to be worth having', function (): void {
    // Anything shorter spends more time starting than running.
    expect(fn () => resourceManifest(
        "name: acme\nimage: acme/web:1\nscale_to_zero: true\nidle_seconds: 2\n"
    ))->toThrow(RuntimeException::class, 'at least 10');
});

it('puts everything away when a project sleeps, and nothing when it does not', function (): void {
    // Scale-to-zero only ever reaches the thing requests arrive at. Measured: a
    // sleeping project with a Postgres, a Valkey and one worker still held three
    // pods and reserved 200m of CPU. Suspending is the half that covers the rest.
    $manifest = resourceManifest(
        "name: acme\nimage: acme/web:1\nresources:\n  db: postgres\n  cache: valkey\nprocesses:\n  queue: php artisan queue:work\n"
    );

    expect($manifest->toServiceSpec()->suspended)->toBeFalse()
        ->and($manifest->toDatabaseSpecs()[0]->suspended)->toBeFalse();

    $asleep = $manifest->withSuspended(true);

    // Every one of them, or the project is not asleep — it is confusing.
    expect($asleep->toServiceSpec()->suspended)->toBeTrue()
        ->and($asleep->toDatabaseSpecs()[0]->suspended)->toBeTrue()
        ->and($asleep->toDatabaseSpecs()[1]->suspended)->toBeTrue();
});

it('does not write sleeping into the file everybody checks out', function (): void {
    // Sleeping is something a developer does to their machine this afternoon,
    // not a property of the application that belongs in its repository.
    $manifest = resourceManifest("name: acme\nimage: acme/web:1\n");

    expect($manifest->suspended)->toBeFalse()
        ->and($manifest->withSuspended(true)->name)->toBe($manifest->name)
        // And the original is untouched: it is a value object, and a command
        // that mutated the manifest would leave the next reader with a project
        // that is asleep for reasons nothing recorded.
        ->and($manifest->suspended)->toBeFalse();
});

it('refuses a setting nothing reads, rather than shrugging at it', function (): void {
    // MEASURED, on a real application: `map:` instead of `bind:` bound a Valkey
    // to CACHE_HOST while the application read REDIS_HOST. The workload started,
    // connected to nothing, and said only `Connection refused` from deep inside
    // a vendor directory. It took a pod inspection to find.
    //
    // The guard caught its own author within a minute of existing: the key list
    // said `idle_timeout` and the reader reads `idle_seconds`.
    expect(fn () => resourceManifest("name: acme\nimage: acme/web:1\ndomian: acme.test\n"))
        ->toThrow(RuntimeException::class, 'nothing reads: domian')
        ->and(fn () => resourceManifest("name: acme\nimage: acme/web:1\nresources:\n  cache:\n    engine: valkey\n    map:\n      host: REDIS_HOST\n"))
        ->toThrow(RuntimeException::class, 'nothing reads: map');

    // And it says what the file DOES take, because a refusal that does not is
    // just a different way of being stuck.
    expect(fn () => resourceManifest("name: acme\nimage: acme/web:1\nnope: 1\n"))
        ->toThrow(RuntimeException::class, 'It takes: build, domains');
});
