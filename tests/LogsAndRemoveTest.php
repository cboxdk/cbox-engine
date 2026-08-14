<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Kubernetes\NodeKubectl;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\CommandResult;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Illuminate\Support\Facades\Artisan;

/*
 * The two halves of the daily loop that were missing: seeing what a project
 * says, and taking it back off again.
 */

function projectAt(string $yaml): string
{
    $root = sys_get_temp_dir().'/cbox-loop-'.getmypid().'-'.substr(md5($yaml), 0, 8);
    @mkdir($root, 0755, true);
    file_put_contents($root.'/cbox.yaml', $yaml);

    return $root;
}

it('reads every pod the selector matches, not the first', function (): void {
    // A service with three replicas whose logs came from one of them is a log
    // that is wrong two thirds of the time, and silently.
    $runner = new FakeCommandRunner;

    (new NodeKubectl($runner))->logs('cbox-acme', 'platform.cbox.dk/service=acme', fn () => null);

    $call = collect($runner->calls)->first(fn (array $c): bool => in_array('logs', $c, true));

    expect($call)->not->toBeNull()
        ->and($call)->toContain('--max-log-requests=20')
        // Which pod said what: without it three replicas interleave into
        // something that reads like one very confused process.
        ->and($call)->toContain('--prefix');
});

it('follows without a bound, and does not otherwise', function (): void {
    // Everything else here has a timeout because a tool that hangs looks like a
    // broken machine. A follow is the case where hanging IS the feature.
    $runner = new class extends FakeCommandRunner
    {
        /** @var list<int|null> */
        public array $timeouts = [];

        public function stream(array $command, callable $onOutput, ?int $timeout = 30): CommandResult
        {
            $this->timeouts[] = $timeout;

            return new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: '');
        }
    };

    $kubectl = new NodeKubectl($runner);
    $kubectl->logs('cbox-acme', 'x=y', fn () => null, follow: true);
    $kubectl->logs('cbox-acme', 'x=y', fn () => null, follow: false);

    expect($runner->timeouts[0])->toBeNull()
        ->and($runner->timeouts[1])->not->toBeNull();
});

it('narrows to one process when asked, and to the service when not', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $path = projectAt("name: acme\nimage: acme/web:1\n");

    Artisan::call('local:logs', ['--path' => $path, '--tail' => 5]);
    Artisan::call('local:logs', ['process' => 'queue', '--path' => $path, '--tail' => 5]);

    expect($kubernetes->tailed[0])->toBe('cbox-acme platform.cbox.dk/service=acme')
        ->and($kubernetes->tailed[1])->toBe('cbox-acme platform.cbox.dk/service=acme,platform.cbox.dk/process=queue');
});

it('refuses to remove a project with nobody there to ask', function (): void {
    // Removing takes the databases and their volumes with it. "I removed the
    // project" and "I deleted my data" are the same command here, so consent
    // that could not be asked for is not assumed.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:remove', [
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Refusing to remove without --force')
        ->and($kubernetes->deleted)->toBe([]);
});

it('removes the namespace, so nothing the compiler learns later is left behind', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:remove', [
        '--path' => projectAt("name: acme\nimage: acme/web:1\nresources:\n  db: postgres\n"),
        '--force' => true,
    ]);

    // Deleting objects one at a time would leave whatever the compiler has
    // learned to emit since this command was written.
    // The project's own certificate goes with it — but only after the gateway
    // has stopped naming it.
    expect($kubernetes->deleted)->toBe([
        'namespace/cbox-acme',
        'certificate/acme-wildcard',
        'secret/acme-wildcard-tls',
    ]);
});

it('removes a project whose directory is gone', function (): void {
    // THE ONE NEITHER COMMAND COULD REACH. `remove` starts by reading a
    // cbox.yaml, and `prune` deliberately never proposes a DEFAULT environment
    // because a project that was MOVED has not been abandoned. So `rm -rf` on a
    // checkout somebody had finished with left the namespace, its Postgres and
    // its volume on the machine forever — the exact outcome prune exists to
    // prevent.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [ManifestDocument::fromArray([
        'apiVersion' => 'v1',
        'kind' => 'ConfigMap',
        'metadata' => [
            'name' => 'cbox-origin',
            'namespace' => 'cbox-abandoned',
            'labels' => [
                'platform.cbox.dk/managed' => 'true',
                'platform.cbox.dk/service' => 'abandoned',
            ],
        ],
        'data' => ['worktree' => '/gone', 'project' => 'abandoned', 'environment' => ''],
    ])];

    $this->app->instance(Kubernetes::class, $kubernetes);

    $this->artisan('local:remove', ['--project' => 'abandoned', '--force' => true])
        ->assertSuccessful();

    expect($kubernetes->deleted)->toContain('namespace/cbox-abandoned');
});

it('names what there was when the project is not one', function (): void {
    // A typo and a project that was never deployed are the same silence
    // otherwise, and an environment's name is `project-environment`, which is
    // not guessable from the outside.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [];

    $this->app->instance(Kubernetes::class, $kubernetes);

    $this->artisan('local:remove', ['--project' => 'typo', '--force' => true])
        ->expectsOutputToContain('is not an environment on this cluster')
        ->assertFailed();

    expect($kubernetes->deleted)->toBe([]);
});

function originFor(string $project, string $environment = ''): ManifestDocument
{
    $name = $environment === '' ? $project : $project.'-'.$environment;

    return ManifestDocument::fromArray([
        'apiVersion' => 'v1',
        'kind' => 'ConfigMap',
        'metadata' => [
            'name' => 'cbox-origin',
            'namespace' => 'cbox-'.$name,
            'labels' => [
                'platform.cbox.dk/managed' => 'true',
                'platform.cbox.dk/service' => $name,
            ],
        ],
        'data' => ['worktree' => '/gone', 'project' => $project, 'environment' => $environment],
    ]);
}

it('removes the environment asked for and not a neighbour', function (): void {
    // THE RISK THIS OPTION CARRIES. Naming a project against the cluster means a
    // wrong match deletes somebody else's namespace, with their database in it.
    // Mutation testing caught the first version of these tests proving nothing:
    // with one environment on the cluster, "match any" and "match the right one"
    // are the same answer.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [
        originFor('alpha'),
        originFor('beta'),
        originFor('beta', 'feature'),
    ];

    $this->app->instance(Kubernetes::class, $kubernetes);

    $this->artisan('local:remove', ['--project' => 'beta', '--force' => true])
        ->assertSuccessful();

    // Its own namespace, and the certificate the shared gateway held for it —
    // never a neighbour's.
    expect($kubernetes->deleted)->toContain('namespace/cbox-beta')
        ->and($kubernetes->deleted)->not->toContain('namespace/cbox-alpha')
        ->and($kubernetes->deleted)->not->toContain('namespace/cbox-beta-feature');
});
