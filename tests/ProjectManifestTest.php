<?php

declare(strict_types=1);

use Cbox\Engine\Platform\LocalTarget;
use Cbox\Engine\Project\CommandLine;
use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ApplyOutcome;

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
