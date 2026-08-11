<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Sandbox\SandboxBuilder;
use Cbox\Engine\Sandbox\SandboxMatrix;
use Cbox\Engine\Sandbox\SandboxTarget;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;
use Illuminate\Support\Facades\Artisan;

/*
 * A package is not an application.
 *
 * It has no HTTP surface until something installs it, so a sandbox is a real
 * Laravel application built around it — on the dev tier, and as a matrix,
 * because "does it work on 8.3 AND 8.4 with Laravel 12 AND 13" is the actual
 * question and it is four applications.
 */

function packageAt(string $name, bool $application = false): string
{
    $root = sys_get_temp_dir().'/cbox-pkg-'.getmypid().'-'.substr(md5($name.$application), 0, 8);
    @mkdir($root, 0755, true);
    file_put_contents($root.'/composer.json', (string) json_encode(['name' => $name, 'type' => 'library']));

    if ($application) {
        file_put_contents($root.'/artisan', '#!/usr/bin/env php');
    }

    return $root;
}

it('builds one sandbox by default and the whole matrix when asked', function (): void {
    // One by default: somebody typing `cbox sandbox` wants an application, and
    // four of them is four dependency resolutions before they see anything.
    expect(SandboxMatrix::parse('', '')->targets())->toHaveCount(1)
        ->and(SandboxMatrix::parse('8.3,8.4', '12,13')->targets())->toHaveCount(4)
        ->and(array_map(
            static fn (SandboxTarget $t): string => $t->environment(),
            SandboxMatrix::parse('8.3,8.4', '12,13')->targets(),
        ))->toBe([
            'php83-laravel12', 'php83-laravel13',
            'php84-laravel12', 'php84-laravel13',
        ]);
});

it('names an environment as a DNS label, dots and all', function (): void {
    // `php8.3-laravel12.pkg.cbox.test` is a different name in a different place
    // from the one anybody meant.
    expect((new SandboxTarget('8.3', '12'))->environment())->toBe('php83-laravel12')
        ->and((new SandboxTarget('8.3', '12'))->environment())->not->toContain('.');
});

it('runs on the dev tier, which is the point', function (): void {
    // Xdebug, SPX and pcov are there, and a package author is exactly the
    // person who wants to step through a request.
    expect((new SandboxTarget('8.4', '13'))->image())
        ->toBe('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.4-bookworm-dev')
        ->and((new SandboxTarget('8.4', '13'))->constraint())->toBe('^13.0');
});

it('refuses a version it cannot build against', function (): void {
    expect(fn () => SandboxMatrix::parse('8', ''))
        ->toThrow(RuntimeException::class, 'Write it as 8.4')
        ->and(fn () => SandboxMatrix::parse('', '13.2'))
        ->toThrow(RuntimeException::class, 'Write the major on its own');
});

it('resolves composer against the image the sandbox will run on', function (): void {
    // The whole point of a version matrix. Resolving on the developer's own PHP
    // answers a different question — and Docker can emulate an architecture
    // that containerd inside the kind node refuses to pull at all.
    $runner = new FakeCommandRunner;
    $package = packageAt('acme/widgets');

    // Deny-by-default fake: every composer call comes back as one that never
    // ran, so the build should refuse rather than report a sandbox.
    expect(fn () => (new SandboxBuilder($runner))->build($package, 'acme/widgets', new SandboxTarget('8.3', '12')))
        ->toThrow(RuntimeException::class, 'Composer refused inside');

    $create = $runner->calls[0];

    expect($create)->toContain('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.3-bookworm-dev')
        // Past the image's entrypoint: it generates nginx and php-fpm config as
        // root, this container runs as the developer, and it failed with three
        // `Permission denied` lines before composer was ever reached.
        ->and($create)->toContain('--entrypoint')
        ->and($create)->toContain('create-project')
        ->and($create)->toContain('laravel/laravel')
        ->and($create)->toContain('^12.0')
        // The package's own directory has to be mounted: the path repository
        // points three levels up and composer has to follow it.
        ->and($create)->toContain($package.':'.$package);
});

it('will not sandbox an application', function (): void {
    // It would scaffold a second Laravel app inside the first and install the
    // app into itself — failing deep in composer, long after writing several
    // hundred megabytes into the repository.
    $exit = Artisan::call('local:sandbox', ['--path' => packageAt('acme/site', application: true), '--no-deploy' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('is an application, not a package');
});

it('refuses a directory with nothing to install', function (): void {
    $empty = sys_get_temp_dir().'/cbox-empty-'.getmypid();
    @mkdir($empty, 0755, true);

    $exit = Artisan::call('local:sandbox', ['--path' => $empty, '--no-deploy' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('no composer.json');
});

it('keeps the vendor in the project name', function (): void {
    // Two vendors' `laravel-cache` on one machine is not unusual, and a sandbox
    // that silently replaced the other one would be the worst possible version
    // of this feature.
    $builder = new SandboxBuilder(new FakeCommandRunner);

    expect($builder->project('cboxdk/laravel-id'))->toBe('cboxdk-laravel-id-sandbox')
        ->and($builder->project('acme/laravel-cache'))->not->toBe($builder->project('other/laravel-cache'));
});

it('keeps two worktrees of one package apart', function (): void {
    // A package developed in two worktrees at once would otherwise put both
    // their sandboxes in `php84-laravel13` — one namespace, whichever deployed
    // last winning. The combination alone is not a unique name for a copy of a
    // package.
    $runner = new FakeCommandRunner;
    // The command resolves the path before asking git, and `/var` is a symlink
    // to `/private/var` on a Mac — a fixture staged on the unresolved path is a
    // fixture that never matches.
    $package = (string) realpath(packageAt('acme/widgets'));
    $runner->stage(
        ['git', '-C', $package, 'rev-parse', '--absolute-git-dir'],
        new CommandResult(ran: true, exitCode: 0, errorOutput: '', output: "/repo/.git/worktrees/try\n"),
    )->stage(
        ['git', '-C', $package, 'branch', '--show-current'],
        new CommandResult(ran: true, exitCode: 0, errorOutput: '', output: "try\n"),
    );
    app()->instance(CommandRunner::class, $runner);

    // The scaffold is already there, so the build is skipped: this is about the
    // name it lands under, not about composer.
    @mkdir($package.'/.cbox/sandbox/php84-laravel13/vendor', 0755, true);

    Artisan::call('local:sandbox', ['--path' => $package, '--no-deploy' => true, '--json' => true]);

    // Parsed as-is, deliberately: `--json` that needs the caller to find where
    // the JSON starts is not machine-readable.
    /** @var array<string, mixed> $output */
    $output = json_decode(Artisan::output(), true);

    // Keyed by the environment it lands in — the one somebody types into
    // `cbox logs --env`, and the only name that is unique per worktree.
    expect(array_keys((array) data_get($output, 'sandboxes')))->toBe(['try-php84-laravel13']);
});
