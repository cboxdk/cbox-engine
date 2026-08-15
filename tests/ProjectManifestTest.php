<?php

declare(strict_types=1);

use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Platform\LocalTarget;
use Cbox\Engine\Project\CommandLine;
use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ApplyOutcome;
use Cbox\Engine\ValueObjects\ManifestDocument;

/*
 * The manifest is the product's most visible surface, and every refusal here
 * happens while the person who wrote the file is looking at it. The compiler
 * refuses the same things and so does the API server, but each of those lands
 * further away and in a vocabulary the developer never opted into.
 */

function writeManifest(string $yaml, string $subdirectory = ''): string
{
    $root = sys_get_temp_dir().'/cbox-manifest-'.getmypid().'-'.substr(md5($yaml.$subdirectory), 0, 8);
    @mkdir($root.($subdirectory !== '' ? '/'.$subdirectory : ''), 0755, true);
    file_put_contents($root.'/'.ProjectManifestReader::FILENAME, $yaml);

    return $subdirectory !== '' ? $root.'/'.$subdirectory : $root;
}

it('finds the manifest from anywhere inside the project', function (): void {
    // A developer runs commands from wherever they happen to be. A tool that
    // only works from the repository root has an unstated precondition, and
    // `cd ../..` is not something anybody should have to think about.
    $nested = writeManifest("name: acme\nimage: acme/web:1\n", 'app/Http/Controllers');

    $found = (new ProjectManifestReader)->find($nested);

    expect($found)->not->toBeNull()
        ->and(basename((string) $found))->toBe('cbox.yaml');
});

it('gives a project a hostname without being asked', function (): void {
    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml'
    );

    // Writing down a hostname is a step with no decision in it, and a hostname
    // is how anybody looks at what they just deployed.
    expect($manifest->domains)->toBe(['acme.cbox.test'])
        ->and($manifest->namespace())->toBe('cbox-acme');
});

it('refuses a name that Kubernetes would refuse, where it can be fixed', function (): void {
    // The same rule the compiler enforces, said here. A name that fails this
    // reaches the API server as an object it rejects, and the message names a
    // field rather than a line in a file.
    expect(fn () => (new ProjectManifestReader)->read(
        writeManifest("name: Acme Corp\nimage: acme/web:1\n").'/cbox.yaml'
    ))->toThrow(RuntimeException::class, 'cannot be a project name');
});

it('carries numbers and booleans into the environment as text', function (): void {
    // Unquoted numbers and booleans are ordinary YAML and refusing them would
    // be pedantry — an environment variable is text by definition.
    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\nenv:\n  WORKERS: 3\n  DEBUG: true\n").'/cbox.yaml'
    );

    expect($manifest->env)->toBe(['WORKERS' => '3', 'DEBUG' => 'true']);
});

it('refuses a structure where a value belongs', function (): void {
    expect(fn () => (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\nenv:\n  THING:\n    a: b\n").'/cbox.yaml'
    ))->toThrow(RuntimeException::class, 'single value');
});

it('turns intent into the same type production compiles from', function (): void {
    $spec = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\nport: 9000\nprocesses:\n  queue: php artisan queue:work\n").'/cbox.yaml'
    )->toServiceSpec();

    // `serviceId` is the project's NAME rather than an opaque id: it becomes
    // app.kubernetes.io/instance, so `kubectl -l` reads as the developer's own
    // vocabulary instead of an identifier they would have to look up.
    expect($spec->serviceId)->toBe('acme')
        ->and($spec->namespace)->toBe('cbox-acme')
        ->and($spec->port)->toBe(9000)
        ->and($spec->processes[0]->name)->toBe('queue')
        ->and($spec->processes[0]->command)->toBe(['php', 'artisan', 'queue:work']);
});

it('splits a command without a shell, and keeps quoted arguments whole', function (): void {
    // A container runs a program, not a shell line. The package takes a list for
    // that reason — but making a developer write YAML lists for every command is
    // a tax they will work around, so the string is split here, carefully.
    expect(CommandLine::parse('php artisan queue:work --queue="high,low"', 'queue'))
        ->toBe(['php', 'artisan', 'queue:work', '--queue=high,low']);

    expect(CommandLine::parse(['php', 'artisan', 'horizon'], 'horizon'))
        ->toBe(['php', 'artisan', 'horizon']);
});

it('refuses a command that only means something to a shell', function (): void {
    // Split naively, `a && b` becomes a program called `a` with an argument
    // `&&`, which fails at runtime with a message about a missing file. The
    // refusal names what is wrong and what to do instead.
    foreach (['migrate && serve', 'serve | tee log', 'serve > out.txt', 'echo $HOME'] as $command) {
        expect(fn () => CommandLine::parse($command, 'web'))
            ->toThrow(RuntimeException::class, 'shell');
    }
});

it('refuses a command that ends inside a quote', function (): void {
    expect(fn () => CommandLine::parse('php artisan "queue:work', 'queue'))
        ->toThrow(RuntimeException::class, 'unclosed quote');
});

it('offers to rebuild a workload whose shape cannot be changed', function (): void {
    // `Deployment.spec.selector` is frozen when the object is created, so a
    // Deployment whose selector has changed cannot be patched — the API server
    // refuses it with `field is immutable` and no amount of retrying moves it.
    //
    // Measured: taking cboxdk/platform 0.6.0, which corrects a selector that
    // used to make a web Deployment adopt its own workers, against a project
    // deployed before it.
    $outcome = new ApplyOutcome(
        succeeded: false,
        applied: 0,
        output: '',
        failure: 'The Deployment "demo" is invalid: spec.selector: Invalid value: ...: field is immutable',
    );

    expect($outcome->blockedByImmutableField())->toBeTrue();

    // And an ordinary failure is not mistaken for it, because the answer is
    // different: most failures mean "fix your manifest", this one means "the
    // object predates the change".
    expect((new ApplyOutcome(false, 0, '', 'admission webhook denied the request'))
        ->blockedByImmutableField())->toBeFalse();
});

it('never rebuilds a workload unless it was asked to', function (): void {
    $kubernetes = new RecordingKubernetes;

    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml'
    );

    new ProjectDeployer($kubernetes, new LocalTarget)->deploy($manifest);

    // Recreating a workload is a brief outage. A tool that decides that on
    // somebody's behalf because an apply was inconvenient is a tool that will
    // one day do it during a demonstration.
    expect($kubernetes->deleted)->toBe([]);
});

it('rebuilds only workloads, and only when asked', function (): void {
    $kubernetes = new RecordingKubernetes;

    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\nprocesses:\n  queue: php artisan queue:work\n").'/cbox.yaml'
    );

    new ProjectDeployer($kubernetes, new LocalTarget)->deploy($manifest, dryRun: false, recreate: true);

    // Everything else in a compiled set updates in place, and deleting a Service
    // would take its address with it for no reason.
    expect($kubernetes->deleted)->toBe([
        'deployment/acme',
        'deployment/acme-queue',
    ]);
});

it('takes away what a deploy stopped asking for', function (): void {
    $kubernetes = new RecordingKubernetes;

    // The scaler from a previous deploy, when the project asked for
    // scale-to-zero and no longer does. Left behind, it takes the Deployment
    // back to zero seconds after the apply put two pods on it.
    $kubernetes->listed = [ManifestDocument::fromArray([
        'apiVersion' => 'http.keda.sh/v1alpha1',
        'kind' => 'HTTPScaledObject',
        'metadata' => [
            'name' => 'acme',
            'namespace' => 'cbox-acme',
            'labels' => ['platform.cbox.dk/managed' => 'true'],
        ],
    ])];

    $outcome = new ProjectDeployer($kubernetes, new LocalTarget)->deploy(
        (new ProjectManifestReader)->read(writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml')
    );

    expect($outcome->swept->removed)->toBe(['HTTPScaledObject/acme'])
        ->and($kubernetes->deleted)->toBe(['httpscaledobject/acme']);
});

it('changes nothing on a dry run, including what it would take away', function (): void {
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [ManifestDocument::fromArray([
        'apiVersion' => 'http.keda.sh/v1alpha1',
        'kind' => 'HTTPScaledObject',
        'metadata' => [
            'name' => 'acme',
            'namespace' => 'cbox-acme',
            'labels' => ['platform.cbox.dk/managed' => 'true'],
        ],
    ])];

    $outcome = new ProjectDeployer($kubernetes, new LocalTarget)->deploy(
        (new ProjectManifestReader)->read(writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml'),
        dryRun: true,
    );

    expect($kubernetes->deleted)->toBe([])
        // ...and still says what it would have taken away, which is the whole
        // question somebody runs a dry run to answer.
        ->and($outcome->swept->removed)->toBe(['HTTPScaledObject/acme']);
});

it('takes the leftover away before it applies, not after', function (): void {
    // MEASURED IN THE WRONG ORDER FIRST. Sweeping after the apply is a race the
    // sweep loses: the apply put two pods on the Deployment, the scaler that had
    // not been deleted yet took it back to zero, and the sweep then removed the
    // scaler — leaving the workload at zero with nothing left to raise it. The
    // deploy after that was correct, which is not what a deploy is for.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [ManifestDocument::fromArray([
        'apiVersion' => 'http.keda.sh/v1alpha1',
        'kind' => 'HTTPScaledObject',
        'metadata' => [
            'name' => 'acme',
            'namespace' => 'cbox-acme',
            'labels' => ['platform.cbox.dk/managed' => 'true'],
        ],
    ])];

    new ProjectDeployer($kubernetes, new LocalTarget)->deploy(
        (new ProjectManifestReader)->read(writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml')
    );

    $deleted = array_search('delete httpscaledobject/acme', $kubernetes->events, true);
    $applied = array_search('apply Deployment/acme', $kubernetes->events, true);

    expect($deleted)->toBeInt()
        ->and($applied)->toBeInt()
        ->and($deleted)->toBeLessThan($applied);
});

it('takes the tunnel address as one of its own names', function (): void {
    // MEASURED: APP_URL is baked into the Deployment at deploy time, and
    // `cbox expose` never touched the workload — so an exposed project kept
    // telling the world it lived at `.cbox.test`, which is a name the person on
    // the other end of the tunnel cannot resolve.
    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\nurl: APP_URL\n").'/cbox.yaml'
    );

    $exposed = $manifest->alsoReachableAt('https://calm-fox-1234.trycloudflare.com');

    expect($exposed->domains)->toContain('calm-fox-1234.trycloudflare.com')
        // ...and keeps its own, because the local address still works and is
        // what somebody at this machine uses.
        ->and($exposed->domains)->toContain('acme.cbox.test');
});

it('is unchanged when this machine does not know the address', function (): void {
    // A token tunnel's hostname lives in Cloudflare, and "exposed, address
    // configured elsewhere" is not an address to compile in.
    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml'
    );

    expect($manifest->alsoReachableAt('')->domains)->toBe($manifest->domains)
        ->and($manifest->alsoReachableAt('acme.cbox.test')->domains)->toBe($manifest->domains);
});

it('tells an exposed application its public address, not its local one', function (): void {
    // The whole point: `url:` names the variable the application reads, and it
    // is resolved from the FIRST addressable domain — so the tunnel's name has
    // to be in place before the URL is worked out, not after.
    $manifest = (new ProjectManifestReader)->read(
        writeManifest("name: acme\nimage: acme/web:1\nurl: APP_URL\ndomains:\n  - tunnel.example.com\n").'/cbox.yaml'
    );

    $resolved = $manifest->withResolvedUrl(HostPorts::high());

    expect($resolved->env['APP_URL'])->toContain('tunnel.example.com');
});

it('deploys an exposed project with its public name on the route', function (): void {
    // THE WIRING, which mutation testing caught as untested: taking the tunnel
    // lookup out of the deploy broke nothing. An exposed project has to compile
    // WITH its public hostname, or the next ordinary deploy quietly takes it
    // off the route again — server-side apply writes the compiled set, and
    // anything outside it is not in the books.
    $kubernetes = new RecordingKubernetes;

    // A tunnel Deployment in the project's namespace is how the cluster says
    // "this project is exposed".
    $kubernetes->listedBySelector['app.kubernetes.io/name=cbox-tunnel'] = [
        ManifestDocument::fromArray([
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => 'cbox-tunnel',
                'namespace' => 'cbox-acme',
                'labels' => ['platform.cbox.dk/service' => 'acme'],
            ],
        ]),
    ];

    $kubernetes->logLine = "INF Your quick Tunnel has been created! Visit it at:\n"
        ."https://calm-fox-1234.trycloudflare.com\n";

    new ProjectDeployer($kubernetes, new LocalTarget)->deploy(
        (new ProjectManifestReader)->read(writeManifest("name: acme\nimage: acme/web:1\n").'/cbox.yaml')
    );

    $hostnames = [];

    foreach ($kubernetes->applied as $document) {
        if ($document->kind() === 'HTTPRoute') {
            $hostnames = [...$hostnames, ...$document->stringsAt('spec', 'hostnames')];
        }
    }

    expect($hostnames)->toContain('calm-fox-1234.trycloudflare.com')
        ->and($hostnames)->toContain('acme.cbox.test');
});
