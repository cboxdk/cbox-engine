<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Platform\ProjectListeners;
use Cbox\Engine\Platform\SharedGateway;
use Cbox\Engine\Project\ProjectRegistry;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Illuminate\Support\Facades\Artisan;

/*
 * Many subdomains, at any depth, over TLS.
 *
 * A TLS wildcard matches exactly ONE label, which is the whole reason this code
 * exists: before it, `demo.cbox.test` answered over HTTPS and
 * `api.demo.cbox.test` was refused at the connection, while both answered over
 * plain HTTP. These pin the shape that fixed it.
 */

/**
 * @param  list<ManifestDocument>  $manifests
 */
function documentNamed(array $manifests, string $kind, string $name): ?ManifestDocument
{
    foreach ($manifests as $manifest) {
        if ($manifest->kind() === $kind && $manifest->name() === $name) {
            return $manifest;
        }
    }

    return null;
}

/**
 * @param  list<ManifestDocument>  $manifests
 * @return array<string, mixed>
 */
function decoded(array $manifests, string $kind, string $name): array
{
    $document = documentNamed($manifests, $kind, $name);

    expect($document)->not->toBeNull("expected a {$kind} named {$name}");

    /** @var array<string, mixed> $body */
    $body = json_decode((string) json_encode($document?->body), true);

    return $body;
}

it('serves every project from one hostname-less listener', function (): void {
    // A listener PER project would need its own hostname, and a hostname on a
    // listener is a second place that has to agree with the routes — the
    // narrower of the two winning silently. One listener that takes every name
    // leaves the routes in charge, and SNI picks the certificate.
    $manifests = (new ProjectListeners)->manifests(['demo' => ['demo.cbox.test'], 'shop' => []]);

    $gateway = decoded($manifests, 'Gateway', 'cbox');

    /** @var list<array<string, mixed>> $listeners */
    $listeners = data_get($gateway, 'spec.listeners');

    $https = array_values(array_filter($listeners, fn (array $l): bool => $l['protocol'] === 'HTTPS'));

    expect($https)->toHaveCount(1)
        ->and($https[0])->not->toHaveKey('hostname')
        ->and(data_get($https[0], 'tls.certificateRefs'))->toBe([
            ['name' => 'cbox-wildcard-tls'],
            ['name' => 'demo-wildcard-tls'],
            ['name' => 'shop-wildcard-tls'],
        ]);
});

it('covers a project below its own name, and any depth it asked for', function (): void {
    // `*.demo.cbox.test` reaches `api.demo.cbox.test` and CANNOT reach
    // `deep.nested.demo.cbox.test`. A name three labels down only works when it
    // is on the certificate by name, so a declared hostname is carried verbatim.
    $manifests = (new ProjectListeners)->manifests([
        'demo' => ['demo.cbox.test', 'deep.nested.demo.cbox.test', 'demo.cbox.test'],
    ]);

    $certificate = decoded($manifests, 'Certificate', 'demo-wildcard');

    expect(data_get($certificate, 'spec.dnsNames'))->toBe([
        '*.demo.cbox.test',
        'demo.cbox.test',
        'deep.nested.demo.cbox.test',
    ])
        ->and(data_get($certificate, 'spec.secretName'))->toBe('demo-wildcard-tls')
        ->and(data_get($certificate, 'spec.issuerRef.name'))->toBe('cbox-ca');
});

it('derives the set rather than accumulating it', function (): void {
    // A gateway that keeps a listener for a project this machine ran once names
    // a secret nobody creates, and a listener whose refs do not resolve stops
    // being programmed — taking every other project with it.
    $manifests = (new ProjectListeners)->manifests(['shop' => []]);

    expect(documentNamed($manifests, 'Certificate', 'demo-wildcard'))->toBeNull()
        ->and(data_get(decoded($manifests, 'Gateway', 'cbox'), 'spec.listeners.1.tls.certificateRefs'))
        ->toBe([['name' => 'cbox-wildcard-tls'], ['name' => 'shop-wildcard-tls']]);
});

it('rewrites the gateway before it deletes the certificate', function (): void {
    // The other order leaves a window — short, and exactly long enough for the
    // controller to notice — where the gateway names a secret that is gone.
    $kubernetes = new RecordingKubernetes;

    $updated = (new SharedGateway($kubernetes, new ProjectRegistry($kubernetes)))->forget('demo');

    $watched = ['apply Gateway/cbox', 'delete certificate/demo-wildcard'];
    $order = array_values(array_filter(
        $kubernetes->events,
        static fn (string $event): bool => in_array($event, $watched, true),
    ));

    expect($updated)->toBeTrue()->and($order)->toBe($watched);
});

it('drops a project whose route the cluster is still listing', function (): void {
    // Its namespace was asked to go a moment ago, and deletion is not instant.
    // Trusting the cluster's list here writes the gateway back WITH the project
    // that is being removed, and then deletes the certificate it just named.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [
        ManifestDocument::fromArray([
            'kind' => 'HTTPRoute',
            'metadata' => ['name' => 'demo', 'labels' => ['platform.cbox.dk/service' => 'demo']],
            'spec' => ['hostnames' => ['demo.cbox.test']],
        ]),
        ManifestDocument::fromArray([
            'kind' => 'HTTPRoute',
            'metadata' => ['name' => 'shop', 'labels' => ['platform.cbox.dk/service' => 'shop']],
            'spec' => ['hostnames' => ['shop.cbox.test']],
        ]),
    ];

    (new SharedGateway($kubernetes, new ProjectRegistry($kubernetes)))->forget('demo');

    expect(data_get(decoded($kubernetes->applied, 'Gateway', 'cbox'), 'spec.listeners.1.tls.certificateRefs'))
        ->toBe([['name' => 'cbox-wildcard-tls'], ['name' => 'shop-wildcard-tls']])
        ->and(documentNamed($kubernetes->applied, 'Certificate', 'demo-wildcard'))->toBeNull();
});

it('keeps the certificate when the gateway could not be rewritten', function (): void {
    // A gateway still naming a certificate is working. A gateway naming one that
    // has been deleted is not, so a failure here leaves more behind, not less.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->applySucceeds = false;

    $updated = (new SharedGateway($kubernetes, new ProjectRegistry($kubernetes)))->forget('demo');

    expect($updated)->toBeFalse()
        ->and($kubernetes->deleted)->toBe([]);
});

it('reads what the cluster is routing, not what the file says now', function (): void {
    // A `cbox.yaml` edited since the last deploy would otherwise take a hostname
    // off a certificate that is still being served.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [
        ManifestDocument::fromArray([
            'kind' => 'HTTPRoute',
            'metadata' => [
                'name' => 'shop',
                'namespace' => 'cbox-shop',
                'labels' => ['platform.cbox.dk/service' => 'shop'],
            ],
            'spec' => ['hostnames' => ['shop.cbox.test', 'api.shop.cbox.test', 7]],
        ]),
        ManifestDocument::fromArray([
            'kind' => 'HTTPRoute',
            'metadata' => ['name' => 'stray', 'namespace' => 'other'],
            'spec' => ['hostnames' => ['stray.cbox.test']],
        ]),
    ];

    expect((new ProjectRegistry($kubernetes))->hostnames())
        // A route with no service label belongs to no project this tool knows,
        // and a hostname that is not a string is not a hostname.
        ->toBe(['shop' => ['shop.cbox.test', 'api.shop.cbox.test']]);
});

it('puts the project being deployed on the gateway with the ones already there', function (): void {
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [
        ManifestDocument::fromArray([
            'kind' => 'HTTPRoute',
            'metadata' => ['name' => 'shop', 'labels' => ['platform.cbox.dk/service' => 'shop']],
            'spec' => ['hostnames' => ['shop.cbox.test']],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--path' => projectAt("name: acme\nimage: acme/web:1\ndomains:\n  - acme.cbox.test\n  - '*.acme.cbox.test'\n"),
        '--dry-run' => true,
    ]);

    $gateway = decoded($kubernetes->applied, 'Gateway', 'cbox');

    expect(data_get($gateway, 'spec.listeners.1.tls.certificateRefs'))->toBe([
        ['name' => 'cbox-wildcard-tls'],
        ['name' => 'acme-wildcard-tls'],
        ['name' => 'shop-wildcard-tls'],
    ])
        // Deploying one project must not drop another project's names.
        ->and(data_get(decoded($kubernetes->applied, 'Certificate', 'shop-wildcard'), 'spec.dnsNames'))
        ->toBe(['*.shop.cbox.test', 'shop.cbox.test'])
        ->and(data_get(decoded($kubernetes->applied, 'Certificate', 'acme-wildcard'), 'spec.dnsNames'))
        ->toBe(['*.acme.cbox.test', 'acme.cbox.test']);
});
