<?php

declare(strict_types=1);

use Cbox\Engine\Addons\AddonInstaller;
use Cbox\Engine\Addons\AddonSet;
use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\Kubernetes\NodeKubectl;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;
use Cbox\Engine\ValueObjects\ManifestDocument;

function applied(FakeCommandRunner $runner): string
{
    foreach ($runner->calls as $index => $call) {
        if (in_array('apply', $call, true)) {
            return $runner->inputs[$index] ?? '';
        }
    }

    return '';
}

it('does not flatten an empty object into an empty array', function (): void {
    // THE BUG THIS CLASS EXISTS FOR, measured against the real cluster. PHP
    // cannot tell `{}` from `[]` once both are associative arrays, so
    // json_decode(..., true) then json_encode rewrites every empty object.
    // A Gateway API CRD carrying `subresources: {status: {}}` reached the API
    // server as `{status: []}` and was refused:
    //
    //   invalid type for ...CustomResourceSubresourceStatus: got "array",
    //   expected "map"
    $documents = ManifestDocument::listFromJson(<<<'JSON'
        [{"apiVersion":"apiextensions.k8s.io/v1","kind":"CustomResourceDefinition",
          "metadata":{"name":"widgets.example.com"},
          "spec":{"versions":[{"name":"v1","subresources":{"status":{}}}]}}]
        JSON);

    $runner = (new FakeCommandRunner)->stage(
        array_merge(kubectlPrefix(), ['apply', '--server-side', '--field-manager=cbox-platform', '--force-conflicts', '-f', '-']),
        new CommandResult(ran: true, exitCode: 0, output: 'created', errorOutput: ''),
    );

    (new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0))->apply($documents);

    expect(applied($runner))->toContain('"status":{}')
        ->and(applied($runner))->not->toContain('"status":[]');
});

it('converts nested maps deeply, and cannot rescue an empty PHP array', function (): void {
    // The conversion goes through JSON rather than a cast: a cast is shallow and
    // would leave `spec` an array while making the top level an object.
    $document = ManifestDocument::fromArray([
        'apiVersion' => 'v1',
        'kind' => 'ConfigMap',
        'metadata' => ['name' => 'deep', 'labels' => ['a' => 'b']],
    ]);

    expect(json_encode($document->body))->toContain('"labels":{"a":"b"}');

    // AND THE LIMIT, asserted rather than hoped for. An empty PHP array carries
    // no record of whether it meant `{}` or `[]`, and no flag fixes it —
    // JSON_FORCE_OBJECT would turn genuine lists into objects, the same bug
    // pointed the other way. A caller that means an empty map says so.
    expect(json_encode(ManifestDocument::fromArray([
        'kind' => 'ConfigMap', 'data' => [],
    ])->body))->toContain('"data":[]');

    expect(json_encode(ManifestDocument::fromArray([
        'kind' => 'ConfigMap', 'data' => new stdClass,
    ])->body))->toContain('"data":{}');
});

it('applies definitions before anything that needs them', function (): void {
    // A set holding both a CRD and an object of that kind is ordinary — every
    // addon chart ships one — and in file order the object arrives before the
    // API server serves its kind. The error is `no matches for kind`, which
    // reads like a typo rather than a race.
    $documents = ManifestDocument::listFromJson(<<<'JSON'
        [{"apiVersion":"example.com/v1","kind":"Widget","metadata":{"name":"a"}},
         {"apiVersion":"apiextensions.k8s.io/v1","kind":"CustomResourceDefinition","metadata":{"name":"widgets.example.com"}}]
        JSON);

    $apply = array_merge(kubectlPrefix(), ['apply', '--server-side', '--field-manager=cbox-platform', '--force-conflicts', '-f', '-']);

    $runner = (new FakeCommandRunner)->stage($apply, new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: ''));

    (new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0))->apply($documents);

    $sent = array_values(array_filter($runner->inputs, is_string(...)));

    expect($sent)->toHaveCount(2)
        ->and($sent[0])->toContain('CustomResourceDefinition')
        ->and($sent[0])->not->toContain('"kind":"Widget"')
        ->and($sent[1])->toContain('"kind":"Widget"');
});

it('creates the namespaces a rendered chart does not carry', function (): void {
    // `helm template` does not emit them: --namespace says where to address
    // things, and creating it is `helm install`'s job, which is not running
    // here. Applied verbatim, the first namespaced object fails with
    // `namespaces "envoy-gateway-system" not found`.
    $directory = sys_get_temp_dir().'/cbox-addons-'.getmypid();
    @mkdir($directory, 0755, true);
    file_put_contents($directory.'/gateway-api-crds.json', '[]');
    file_put_contents($directory.'/envoy-gateway.json', json_encode([[
        'apiVersion' => 'v1', 'kind' => 'ServiceAccount',
        'metadata' => ['name' => 'envoy-gateway', 'namespace' => 'envoy-gateway-system'],
    ]]));
    file_put_contents($directory.'/cert-manager.json', '[]');
    file_put_contents($directory.'/cnpg.json', '[]');

    $apply = array_merge(kubectlPrefix(), ['apply', '--server-side', '--field-manager=cbox-platform', '--force-conflicts', '-f', '-']);
    $runner = (new FakeCommandRunner)->stage($apply, new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: ''));

    (new AddonInstaller(new AddonSet($directory), new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0), new ClusterObjects))->install();

    $sent = implode("\n", array_filter($runner->inputs, is_string(...)));

    expect($sent)->toContain('"kind":"Namespace"')
        ->and($sent)->toContain('envoy-gateway-system');
});

it('stops at the first addon that fails, instead of piling errors on it', function (): void {
    $directory = sys_get_temp_dir().'/cbox-addons-fail-'.getmypid();
    @mkdir($directory, 0755, true);
    file_put_contents($directory.'/gateway-api-crds.json', json_encode([[
        'apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'x'],
    ]]));

    // Nothing staged: the apply fails. cert-manager applied into a cluster whose
    // Gateway API kinds never appeared produces a second, more confusing error
    // on top of the first.
    $results = (new AddonInstaller(
        new AddonSet($directory),
        new NodeKubectl(new FakeCommandRunner, establishAttempts: 0, establishDelay: 0),
        new ClusterObjects,
    ))->install();

    expect($results)->toHaveCount(1)
        ->and($results[0]->succeeded)->toBeFalse();
});

/**
 * @return list<string>
 */
function kubectlPrefix(): array
{
    return ['docker', 'exec', '-i', 'cbox-control-plane', 'kubectl', '--kubeconfig', '/etc/kubernetes/admin.conf'];
}

it('writes an empty map as an object where cert-manager needs one', function (): void {
    // `selfSigned: {}` is how you say "sign this yourself". As an empty PHP
    // array it encodes as `[]` and cert-manager refuses the issuer — the same
    // failure the Gateway API bundle produced, in code we write rather than
    // vendor.
    $manifests = (new ClusterObjects)->manifests();

    $encoded = implode("\n", array_map(
        static fn ($m): string => (string) json_encode($m->body),
        $manifests,
    ));

    expect($encoded)->toContain('"selfSigned":{}')
        ->and($encoded)->not->toContain('"selfSigned":[]');
});

it('applies the cluster objects after the addons that define their kinds', function (): void {
    // The GatewayClass needs the Gateway API, the EnvoyProxy needs Envoy
    // Gateway's own CRDs, the issuers need cert-manager's. Applied first they
    // fail with `no matches for kind`.
    $directory = sys_get_temp_dir().'/cbox-addons-order-'.getmypid();
    @mkdir($directory, 0755, true);
    foreach (['gateway-api-crds', 'envoy-gateway', 'cert-manager', 'cnpg', 'keda', 'keda-add-ons-http'] as $name) {
        file_put_contents($directory.'/'.$name.'.json', '[]');
    }

    $apply = array_merge(kubectlPrefix(), ['apply', '--server-side', '--field-manager=cbox-platform', '--force-conflicts', '-f', '-']);
    $runner = (new FakeCommandRunner)->stage($apply, new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: ''));

    $results = (new AddonInstaller(
        new AddonSet($directory),
        new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0),
        new ClusterObjects,
    ))->install();

    // The last one, and there must BE a last one: an empty result would make
    // the assertion vacuous.
    expect($results)->not->toBeEmpty();
    expect($results[count($results) - 1]->name)->toBe('cluster-objects');
});

it('waits for an addon to be ANSWERING before applying what needs it', function (): void {
    // MEASURED on a fresh cluster. The cluster objects reached cert-manager's
    // validating webhook seconds after its Deployment was created, and the API
    // server refused all four with
    //
    //   failed calling webhook "webhook.cert-manager.io": ... connection refused
    //
    // An addon is not installed when its objects are stored. It is installed
    // when its controllers and webhooks answer.
    $directory = sys_get_temp_dir().'/cbox-addons-wait-'.getmypid();
    @mkdir($directory, 0755, true);
    file_put_contents($directory.'/gateway-api-crds.json', '[]');
    file_put_contents($directory.'/envoy-gateway.json', '[]');
    file_put_contents($directory.'/cert-manager.json', json_encode([[
        'apiVersion' => 'apps/v1', 'kind' => 'Deployment',
        'metadata' => ['name' => 'cert-manager-webhook', 'namespace' => 'cert-manager'],
    ]]));
    file_put_contents($directory.'/cnpg.json', '[]');

    $apply = array_merge(kubectlPrefix(), ['apply', '--server-side', '--field-manager=cbox-platform', '--force-conflicts', '-f', '-']);
    $wait = array_merge(kubectlPrefix(), ['wait', '--for=condition=Available', '--timeout=10s', 'deployment', '--all', '-n', 'cert-manager']);

    $runner = (new FakeCommandRunner)
        ->stage($apply, new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: ''))
        ->stage($wait, new CommandResult(ran: true, exitCode: 0, output: 'condition met', errorOutput: ''));

    (new AddonInstaller(
        new AddonSet($directory),
        new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0),
        new ClusterObjects,
    ))->install();

    expect($runner->wasRun($wait))->toBeTrue();
});

it('does not wait during a dry run, which has no pods to wait for', function (): void {
    $directory = sys_get_temp_dir().'/cbox-addons-dry-'.getmypid();
    @mkdir($directory, 0755, true);
    foreach (['gateway-api-crds', 'envoy-gateway', 'cert-manager', 'cnpg', 'keda', 'keda-add-ons-http'] as $name) {
        file_put_contents($directory.'/'.$name.'.json', json_encode([[
            'apiVersion' => 'v1', 'kind' => 'ConfigMap',
            'metadata' => ['name' => 'x', 'namespace' => 'cert-manager'],
        ]]));
    }

    $runner = new FakeCommandRunner;
    $dryApply = array_merge(kubectlPrefix(), ['apply', '--server-side', '--field-manager=cbox-platform', '--force-conflicts', '-f', '-', '--dry-run=server']);
    $runner->stage($dryApply, new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: ''));

    (new AddonInstaller(
        new AddonSet($directory),
        new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0),
        new ClusterObjects,
    ))->install(dryRun: true);

    // Waiting for a workload that was never created would hang until the
    // timeout, on every dry run.
    expect(collect($runner->calls)->contains(fn (array $c): bool => in_array('wait', $c, true)))->toBeFalse();
});

it('publishes the gateway on the node ports the cluster maps to the host', function (): void {
    // kind's port mappings are fixed when the cluster is BUILT, so a randomly
    // allocated NodePort lands somewhere the host cannot reach. Pinning them is
    // also why there is one shared gateway: two services cannot hold the same
    // node port.
    $encoded = implode("\n", array_map(
        static fn ($m): string => (string) json_encode($m->body),
        (new ClusterObjects)->manifests(),
    ));

    expect($encoded)->toContain('"nodePort":'.ClusterConfig::HTTP_NODE_PORT)
        ->and($encoded)->toContain('"nodePort":'.ClusterConfig::HTTPS_NODE_PORT);

    $config = (new ClusterConfig(sys_get_temp_dir().'/cbox-ports.yaml'))->render();

    expect($config)->toContain('containerPort: '.ClusterConfig::HTTP_NODE_PORT)
        ->and($config)->toContain('containerPort: '.ClusterConfig::HTTPS_NODE_PORT);
});
