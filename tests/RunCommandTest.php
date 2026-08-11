<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Kubernetes\NodeKubectl;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\CommandResult;
use Illuminate\Support\Facades\Artisan;

/*
 * Running a command inside the project.
 *
 * The command that decides whether this is a tool or a wrapper: a developer runs
 * `artisan migrate` twenty times a day, and a platform that cannot do it is one
 * they keep `kubectl exec -it -n cbox-thing …` beside.
 */

it('runs in the project running web pod', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:run', [
        'args' => ['php', 'artisan', 'migrate'],
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--no-tty' => true,
    ]);

    expect($kubernetes->executed)->toBe([
        'cbox-acme platform.cbox.dk/service=acme,platform.cbox.dk/process=web :: php artisan migrate',
    ]);
});

it('passes the program own exit code through', function (): void {
    // A wrapper that returns 0 because it successfully ran something that
    // failed breaks every script it is put in.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->execExit = 42;
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:run', [
        'args' => ['false'],
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--no-tty' => true,
    ]);

    expect($exit)->toBe(42);
});

it('says there is nowhere to run rather than that the command failed', function (): void {
    $kubernetes = new RecordingKubernetes;
    $kubernetes->execExit = -1;
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:run', [
        'args' => ['php', '-v'],
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--no-tty' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('no running web process');
});

it('reaches a worker when asked, not only the web process', function (): void {
    // `artisan queue:restart` in the web pod tells the wrong process.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:run', [
        'args' => ['ps'],
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--process' => 'queue',
        '--no-tty' => true,
    ]);

    expect($kubernetes->executed[0])->toContain('platform.cbox.dk/process=queue');
});

it('runs in the environment the caller is in', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:artisan', [
        'args' => ['migrate'],
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--env' => 'feature-x',
        '--no-tty' => true,
    ]);

    expect($kubernetes->executed[0])
        ->toContain('cbox-acme-feature-x ')
        ->toContain('service=acme-feature-x')
        // The shortcut is exactly `cbox run -- php artisan …`.
        ->toContain(':: php artisan migrate');
});

it('puts the right program in front for each shortcut', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $path = projectAt("name: acme\nimage: acme/web:1\n");

    Artisan::call('local:composer', ['args' => ['install'], '--path' => $path, '--no-tty' => true]);
    Artisan::call('local:npm', ['args' => ['run', 'build'], '--path' => $path, '--no-tty' => true]);

    expect($kubernetes->executed[0])->toContain(':: composer install')
        ->and($kubernetes->executed[1])->toContain(':: npm run build');
});

it('refuses to run nothing', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:run', [
        'args' => [],
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--no-tty' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($kubernetes->executed)->toBe([]);
});

it('picks a running pod and not one on its way out', function (): void {
    // A terminating or pending pod refuses the connection with a message about
    // the container not being ready, which reads like the command was wrong.
    $runner = new FakeCommandRunner;
    $runner->stage(
        ['docker', 'exec', '-i', 'cbox-control-plane', 'kubectl', '--kubeconfig',
            '/etc/kubernetes/admin.conf', 'get', 'pod', '-l', 'app=x', '-o', 'json', '-n', 'cbox-acme'],
        new CommandResult(ran: true, exitCode: 0, errorOutput: '', output: (string) json_encode([
            'items' => [
                ['metadata' => ['name' => 'going', 'deletionTimestamp' => 'now'], 'status' => ['phase' => 'Running']],
                ['metadata' => ['name' => 'starting'], 'status' => ['phase' => 'Pending']],
                ['metadata' => ['name' => 'the-one'], 'status' => ['phase' => 'Running']],
            ],
        ])),
    );

    (new NodeKubectl($runner))->exec('cbox-acme', 'app=x', ['php', '-v'], tty: false);

    // NOT "the first call containing exec" — `docker exec … get pod` contains it
    // too, and a test that matched that would pass whatever pod was chosen.
    $exec = collect($runner->calls)->first(fn (array $c): bool => in_array('-v', $c, true));

    expect($exec)->not->toBeNull()
        ->and($exec)->toContain('the-one')
        ->and($exec)->not->toContain('going')
        ->and($exec)->not->toContain('starting');
});
