<?php

declare(strict_types=1);

use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Platform\LocalTarget;
use Cbox\Platform\Capability\CertificateSource;
use Cbox\Platform\Compile\EnvironmentGatewayCompiler;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Manifest\Manifest;
use Cbox\Platform\Manifest\ManifestSet;
use Cbox\Platform\Route\EnvironmentGatewaySpec;
use Cbox\Platform\Service\ServiceSpec;

/*
 * Every difference between a laptop and a cell is a capability, and none of them
 * is a branch in a compiler. This is the whole of Cbox Local's side of that
 * rule, so it is worth pinning: a value that drifts here changes what every
 * project compiles to, silently.
 */

function localSpec(): ServiceSpec
{
    return new ServiceSpec(
        serviceId: '01J0000000000000000000SVC1',
        organizationId: '01J0000000000000000000ORG1',
        namespace: 'cbox-acme',
        name: 'web',
        image: 'ghcr.io/acme/web:1',
        port: 8080,
        replicas: 1,
        domains: ['acme.cbox.test'],
    );
}

function compileLocal(): ManifestSet
{
    return new ServiceCompiler((new LocalTarget)->make(HostPorts::high()))->compile(localSpec());
}

it('does not compile a gateway, because the cluster has one', function (): void {
    $set = new EnvironmentGatewayCompiler((new LocalTarget)->make(HostPorts::high()))
        ->compile(new EnvironmentGatewaySpec(
            environmentId: '01J0000000000000000000ENV1',
            organizationId: '01J0000000000000000000ORG1',
            namespace: 'cbox-acme',
            domains: ['acme.cbox.test'],
        ));

    // Its listeners, its wildcard certificate and its client-address policy all
    // belong to whoever installed it — which here is `cbox:addons`.
    expect($set->manifests)->toBe([]);
});

it('points a project route at the cluster gateway', function (): void {
    $route = compileLocal()->find('HTTPRoute/web');

    expect($route)->toBeInstanceOf(Manifest::class);
    assert($route instanceof Manifest);

    // Across the namespace boundary. Without the namespace the route would look
    // for a gateway in the project's own namespace and sit Accepted=False.
    expect(data_get($route->body, 'spec.parentRefs.0'))
        ->toBe(['name' => 'cbox', 'namespace' => 'cbox-system']);
});

it('never asks a public authority to reach a laptop', function (): void {
    $target = (new LocalTarget)->make(HostPorts::high());

    // ACME's HTTP-01 challenge needs the authority to reach the hostname from
    // the public internet. Inheriting the default would compile an Issuer whose
    // orders never validate — no TLS on any hostname, and nothing reporting it.
    expect($target->certificates->source)->toBe(CertificateSource::CertificateAuthority)
        ->and($target->certificates->needsInboundReachability())->toBeFalse();
});

it('spreads nothing across a machine that has one node', function (): void {
    $workload = compileLocal()->find('Deployment/web');

    expect($workload)->toBeInstanceOf(Manifest::class);
    assert($workload instanceof Manifest);

    expect(data_get($workload->body, 'spec.template.spec.topologySpreadConstraints'))->toBeNull();
});

it('runs the same gateway implementation a cell runs', function (): void {
    // The one capability here that says something is the SAME. It is why
    // ClientTrafficPolicy — the object deciding what an application believes
    // about its client — is compiled locally rather than being a production-only
    // path nobody exercises until it is wrong.
    expect((new LocalTarget)->make(HostPorts::high())->gateway->hasEnvoyClientTrafficPolicy)->toBeTrue();
});

it('announces the port a browser is actually on, and only when it is not 443', function (): void {
    // Found by running one application, whose routes are host-bound: it answered, and
    // then redirected to `https://app-hostname.cbox.test/login` — no port, so
    // the browser goes to whatever holds 443. Which on this machine is Herd.
    $route = fn (HostPorts $ports): array => collect(
        new ServiceCompiler((new LocalTarget)->make($ports))->compile(localSpec())->manifests
    )->firstWhere('kind', 'HTTPRoute')->body['spec']['rules'][0];

    expect($route(HostPorts::high())['filters'])->toBe([[
        'type' => 'RequestHeaderModifier',
        'requestHeaderModifier' => [
            'set' => [['name' => 'X-Forwarded-Port', 'value' => (string) HostPorts::HIGH_HTTPS]],
        ],
    ]])
        // On 443 there is nothing to say, and saying it would put a filter in
        // every plan that differs from a cell's for no reason.
        ->and($route(HostPorts::privileged()))->not->toHaveKey('filters');
});
