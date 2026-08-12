<?php

declare(strict_types=1);

namespace Cbox\Engine\Tests;

use Cbox\Engine\Project\LinkedPackages;

/*
 * A composer path repository is a symlink out of the project. It resolves on the
 * machine and dangles in the pod, and the failure names a file that is plainly
 * there — measured on a real project whose queue worker crash-looped for a day.
 */

function projectLinking(array $repositories, string $suffix): string
{
    $root = sys_get_temp_dir().'/cbox-linked-'.$suffix.'-'.getmypid();
    @mkdir($root.'/app', 0755, true);
    @mkdir($root.'/sibling/src', 0755, true);
    @mkdir($root.'/app/inside/pkg', 0755, true);

    file_put_contents($root.'/app/composer.json', json_encode(['repositories' => $repositories]));

    return $root;
}

it('mounts a sibling checkout where the link lands inside the container', function (): void {
    // `vendor/acme/thing -> ../../../sibling` seen from /var/www/html/vendor/acme
    // resolves to /var/www/sibling, so that is where the directory has to appear.
    $root = projectLinking([['type' => 'path', 'url' => '../sibling']], 'basic');

    $mounts = (new LinkedPackages)->forProject($root.'/app', '/var/www/html');

    expect($mounts)->toHaveCount(1)
        ->and($mounts[0]->mountPath)->toBe('/var/www/sibling')
        ->and($mounts[0]->hostPath)->toBe(realpath($root.'/sibling'));
});

it('ignores a path repository that lives inside the project', function (): void {
    // Already mounted with the source; mounting it again would shadow the
    // directory with a copy of itself.
    $root = projectLinking([['type' => 'path', 'url' => 'inside/pkg']], 'inside');

    expect((new LinkedPackages)->forProject($root.'/app', '/var/www/html'))->toBe([]);
});

it('reads path repositories written as an object, which composer also accepts', function (): void {
    // Keyed by name is the form the project that found this bug happens to use,
    // and a list-only reader would have seen no repositories at all.
    $root = projectLinking(['sibling' => ['type' => 'path', 'url' => '../sibling']], 'object');

    expect((new LinkedPackages)->forProject($root.'/app', '/var/www/html'))->toHaveCount(1);
});

it('leaves alone what it cannot resolve', function (): void {
    // A wildcard is a set of paths, an absolute one is somebody's own machine,
    // and a url pointing at nothing is a repository composer never installed.
    $root = projectLinking([
        ['type' => 'path', 'url' => '../packages/*'],
        ['type' => 'path', 'url' => '/opt/elsewhere'],
        ['type' => 'path', 'url' => '../not-there'],
        ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
    ], 'unresolvable');

    expect((new LinkedPackages)->forProject($root.'/app', '/var/www/html'))->toBe([]);
});

it('says nothing about a project with no composer.json', function (): void {
    expect((new LinkedPackages)->forProject(sys_get_temp_dir().'/cbox-nope-'.getmypid(), '/var/www/html'))
        ->toBe([]);
});
