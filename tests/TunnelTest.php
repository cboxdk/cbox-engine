<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Tests\RecordingCluster;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\Tunnel\CloudflareTunnel;
use Cbox\Engine\Tunnel\TunnelMode;
use Cbox\Engine\Tunnel\TunnelRoute;
use Cbox\Engine\Tunnel\TunnelSpec;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Illuminate\Support\Facades\Artisan;

/*
 * Reaching a local environment from outside this machine.
 *
 * The point of a tunnel here is not "the internet can see it" — it is that what
 * the internet sees is the SAME path a browser on this machine takes. These pin
 * the parts that make that true, most of which are one flag each and all of
 * which are silent when wrong.
 */

const GATEWAY = 'envoy-cbox-system-cbox-445c9218.envoy-gateway-system.svc.cluster.local';

/**
 * @return list<ManifestDocument>
 */
function tunnelManifests(TunnelSpec $spec, bool $trustedCa = true): array
{
    return (new CloudflareTunnel('cloudflare/cloudflared:2026.7.3'))
        ->manifests('cbox-demo', 'demo', GATEWAY, $spec, $trustedCa);
}

it('speaks TLS to the gateway, verified against this machine own CA', function (): void {
    // Plain HTTP is one flag shorter and tells every application behind it that
    // the request arrived over `http`. Measured through a real tunnel: over HTTP
    // the application saw `X-Forwarded-Proto: http` for a request the browser
    // made over HTTPS; over TLS it saw `https`. That difference is where
    // redirect loops and http:// asset URLs come from.
    $spec = new TunnelSpec(TunnelMode::Quick, [new TunnelRoute('', 'demo.cbox.test')]);

    $deployment = decoded(tunnelManifests($spec), 'Deployment', 'cbox-tunnel');

    expect(data_get($deployment, 'spec.template.spec.containers.0.args'))->toBe([
        'tunnel', '--no-autoupdate', '--metrics', '0.0.0.0:2000',
        '--url', 'https://'.GATEWAY.':443',
        '--http-host-header', 'demo.cbox.test',
        '--origin-server-name', 'demo.cbox.test',
        '--origin-ca-pool', '/etc/cbox/ca.crt',
    ]);
});

it('takes the CA certificate and leaves its private key behind', function (): void {
    // The CA secret holds the key that signs every name on this machine, and
    // this container talks to the internet.
    $deployment = decoded(
        tunnelManifests(new TunnelSpec(TunnelMode::Quick, [new TunnelRoute('', 'demo.cbox.test')])),
        'Deployment',
        'cbox-tunnel',
    );

    expect(data_get($deployment, 'spec.template.spec.volumes.0.secret'))->toBe([
        'secretName' => 'cbox-ca',
        'items' => [['key' => 'tls.crt', 'path' => 'ca.crt']],
    ]);
});

it('falls back to plain HTTP when the project has no copy of the CA', function (): void {
    // Reachable, and honest about being a slightly different path — rather than
    // a pod stuck on a volume that cannot be mounted.
    $manifests = tunnelManifests(
        new TunnelSpec(TunnelMode::Quick, [new TunnelRoute('', 'demo.cbox.test')]),
        trustedCa: false,
    );

    $deployment = decoded($manifests, 'Deployment', 'cbox-tunnel');

    /** @var list<string> $arguments */
    $arguments = data_get($deployment, 'spec.template.spec.containers.0.args');

    expect($arguments)->toContain('http://'.GATEWAY.':80')
        ->and($arguments)->not->toContain('--origin-ca-pool')
        ->and(data_get($deployment, 'spec.template.spec.volumes'))->toBe([]);
});

it('never runs two connectors at once', function (): void {
    // A rolling update starts the new connector before stopping the old one, and
    // two connectors on a quick tunnel register two different public addresses.
    // Measured: two pods, two trycloudflare hostnames, the stale one reported.
    $deployment = decoded(
        tunnelManifests(new TunnelSpec(TunnelMode::Quick, [new TunnelRoute('', 'demo.cbox.test')])),
        'Deployment',
        'cbox-tunnel',
    );

    expect(data_get($deployment, 'spec.strategy.rollingUpdate.maxSurge'))->toBe(0);
});

it('carries a token in the environment and writes it as data, not stringData', function (): void {
    // A key written as stringData can never be REMOVED by a later apply, so
    // switching a tunnel from a token to credentials would leave the old token
    // in the namespace.
    $manifests = tunnelManifests(new TunnelSpec(TunnelMode::Token, [], 'a-token'));

    $secret = decoded($manifests, 'Secret', 'cbox-tunnel');
    $deployment = decoded($manifests, 'Deployment', 'cbox-tunnel');

    expect($secret)->not->toHaveKey('stringData')
        ->and(data_get($secret, 'data.token'))->toBe(base64_encode('a-token'))
        ->and(data_get($deployment, 'spec.template.spec.containers.0.env.0'))->toBe([
            'name' => 'TUNNEL_TOKEN',
            'valueFrom' => ['secretKeyRef' => ['name' => 'cbox-tunnel', 'key' => 'token']],
        ])
        // Its routing lives in Cloudflare, so there is nothing here to configure.
        ->and(documentNamed($manifests, 'ConfigMap', 'cbox-tunnel'))->toBeNull();
});

it('routes several public hostnames through one tunnel it holds the credentials for', function (): void {
    // The only mode whose ingress is ours, and therefore the only one that can
    // put a real domain and its subdomains through a single tunnel.
    $spec = new TunnelSpec(
        TunnelMode::Credentials,
        [
            new TunnelRoute('app.example.com', 'demo.cbox.test'),
            new TunnelRoute('api.example.com', 'api.demo.cbox.test'),
        ],
        (string) json_encode(['AccountTag' => 'x', 'TunnelID' => 'b3f1', 'TunnelSecret' => 'y']),
    );

    $config = decoded(tunnelManifests($spec), 'ConfigMap', 'cbox-tunnel');

    /** @var array<string, string> $data */
    $data = data_get($config, 'data');

    expect($data['config.yaml'] ?? null)->toBe(<<<'YAML'
        tunnel: "b3f1"
        credentials-file: "/etc/cloudflared/credentials.json"
        no-autoupdate: true
        metrics: "0.0.0.0:2000"
        ingress:
          - hostname: "app.example.com"
            service: "https://envoy-cbox-system-cbox-445c9218.envoy-gateway-system.svc.cluster.local:443"
            originRequest:
              httpHostHeader: "demo.cbox.test"
              originServerName: "demo.cbox.test"
              caPool: "/etc/cbox/ca.crt"
          - hostname: "api.example.com"
            service: "https://envoy-cbox-system-cbox-445c9218.envoy-gateway-system.svc.cluster.local:443"
            originRequest:
              httpHostHeader: "api.demo.cbox.test"
              originServerName: "api.demo.cbox.test"
              caPool: "/etc/cbox/ca.crt"
          - service: "http_status:404"

        YAML);
});

it('refuses credentials that are not a cloudflared credentials file', function (): void {
    // The id is in the file. Asking for it separately is a way to be told one
    // that does not match, which fails at connection time with an error about
    // authentication and sends somebody looking in the wrong place.
    $spec = new TunnelSpec(
        TunnelMode::Credentials,
        [new TunnelRoute('app.example.com', 'demo.cbox.test')],
        '{"nope": true}',
    );

    expect(fn () => $spec->tunnelId())->toThrow(RuntimeException::class, 'no TunnelID');
});

it('refuses the combinations that cannot work', function (): void {
    expect(fn () => new TunnelSpec(TunnelMode::Token))
        ->toThrow(RuntimeException::class, 'cannot run without its credentials')
        // A quick tunnel is ONE address that Cloudflare picks.
        ->and(fn () => new TunnelSpec(TunnelMode::Quick, [
            new TunnelRoute('', 'a.cbox.test'),
            new TunnelRoute('', 'b.cbox.test'),
        ]))->toThrow(RuntimeException::class, 'exactly one local hostname')
        ->and(fn () => new TunnelSpec(TunnelMode::Credentials, [], '{"TunnelID":"x"}'))
        ->toThrow(RuntimeException::class, 'at least one has to be named');
});

it('reads a public hostname with or without the local one it arrives as', function (): void {
    expect(TunnelRoute::parse('app.example.com', 'demo.cbox.test')->local)->toBe('demo.cbox.test')
        ->and(TunnelRoute::parse('app.example.com:api.demo.cbox.test', 'demo.cbox.test')->local)
        ->toBe('api.demo.cbox.test')
        ->and(TunnelRoute::parse('app.example.com:api.demo.cbox.test', '')->external)->toBe('app.example.com')
        // A project with no domains has nothing to default to, and guessing one
        // would route the traffic somewhere surprising.
        ->and(fn () => TunnelRoute::parse('app.example.com', ''))
        ->toThrow(RuntimeException::class, 'needs the local hostname')
        ->and(fn () => TunnelRoute::parse('a:b:c', 'demo.cbox.test'))
        ->toThrow(RuntimeException::class, 'is not a hostname');
});

it('will not expose a project that is not on the cluster', function (): void {
    $kubernetes = new class extends RecordingKubernetes
    {
        public function read(string $kind, string $name, string $namespace): ?ManifestDocument
        {
            return null;
        }
    };
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:expose', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Deploy it first')
        ->and($kubernetes->applied)->toBe([]);
});

it('says which projects are answering the internet', function (): void {
    // The failure mode is not that somebody cannot open a tunnel — it is that
    // three days later a laptop is still answering the internet and nobody
    // remembers. An address that does not appear here is one nobody will close.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector = [
        'platform.cbox.dk/managed=true' => [
            ManifestDocument::fromArray([
                'kind' => 'Deployment',
                'metadata' => [
                    'namespace' => 'cbox-demo',
                    'labels' => ['platform.cbox.dk/service' => 'demo', 'platform.cbox.dk/process' => 'web'],
                ],
                'spec' => ['replicas' => 1],
                'status' => ['readyReplicas' => 1],
            ]),
        ],
        'app.kubernetes.io/name=cbox-tunnel' => [
            ManifestDocument::fromArray([
                'kind' => 'Deployment',
                'metadata' => [
                    'name' => 'cbox-tunnel',
                    'namespace' => 'cbox-demo',
                    'labels' => ['platform.cbox.dk/service' => 'demo'],
                ],
            ]),
        ],
    ];
    $kubernetes->logLine = "INF +----+\n|  https://calm-forest-1234.trycloudflare.com  |\n";
    app()->instance(Kubernetes::class, $kubernetes);
    // The cluster is faked too: `cbox status` asks nothing of the cluster when
    // it is not running, so this used to pass only while the developer happened
    // to have one up.
    app()->instance(ClusterManager::class, new RecordingCluster);

    Artisan::call('local:status');

    expect(Artisan::output())->toContain('https://calm-forest-1234.trycloudflare.com')
        // And the connector is not counted as one of the project's own
        // processes: it is labelled with the project, deliberately, and carries
        // no managed label for exactly this reason.
        ->toContain('demo — 1/1 running');
});

it('takes the credentials away with the connector, and in that order', function (): void {
    // A Secret holding a Cloudflare tunnel token, left behind after the tunnel it
    // authorised was stopped, is a live credential nobody is thinking about.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:unexpose', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($kubernetes->deleted)->toBe([
        'deployment/cbox-tunnel',
        'configmap/cbox-tunnel',
        'secret/cbox-tunnel',
    ]);
});
