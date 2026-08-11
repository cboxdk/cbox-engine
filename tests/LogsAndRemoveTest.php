<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Kubernetes\NodeKubectl;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\CommandResult;
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
