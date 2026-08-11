<?php

declare(strict_types=1);

namespace Cbox\Engine\Tests;

use Cbox\Engine\Project\ImageDigest;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;

function digester(?CommandResult $answer = null): array
{
    $runner = new FakeCommandRunner;

    if ($answer !== null) {
        $runner->stage([
            'docker', 'buildx', 'imagetools', 'inspect', '--format', '{{json .Manifest.Digest}}',
            'ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm',
        ], $answer);
    }

    return [new ImageDigest($runner), $runner];
}

it('pins a tag to the content it points at today', function (): void {
    // The defect this exists for: a node keeps the layers it first pulled for a
    // tag, so a rebuilt base image never reaches a running project — and the
    // pod spec never changes, so nothing restarts either.
    [$digests] = digester(new CommandResult(true, 0, '"sha256:'.str_repeat('a', 64).'"'."\n", ''));

    expect($digests->pin('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm'))
        ->toBe('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx@sha256:'.str_repeat('a', 64));
});

it('leaves the tag alone when the registry cannot be reached', function (): void {
    // A laptop on a train. Refusing to deploy would be worse than deploying the
    // layers the node already has.
    [$digests] = digester(new CommandResult(false, 1, '', 'no such host'));

    expect($digests->pin('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm'))
        ->toBe('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm');
});

it('asks nothing about an image there is nothing to ask about', function (): void {
    [$digests, $runner] = digester();

    $already = 'ghcr.io/acme/app@sha256:'.str_repeat('b', 64);

    expect($digests->pin($already))->toBe($already)
        ->and($digests->pin('cbox-local/example-app:abc123'))->toBe('cbox-local/example-app:abc123')
        ->and($digests->pin(''))->toBe('')
        // Nothing reached the host at all — a locally built image has no
        // registry, and asking would be a wasted second on every deploy.
        ->and($runner->calls)->toBe([]);
});

it('does not mistake a registry port for a tag', function (): void {
    // `localhost:5000/app:1.0` — the first colon is a port. Cutting there would
    // ask about `localhost` and pin the wrong thing.
    $runner = new FakeCommandRunner;
    $runner->stage([
        'docker', 'buildx', 'imagetools', 'inspect', '--format', '{{json .Manifest.Digest}}',
        'localhost:5000/acme/app:1.0',
    ], new CommandResult(true, 0, '"sha256:'.str_repeat('c', 64).'"', ''));

    expect((new ImageDigest($runner))->pin('localhost:5000/acme/app:1.0'))
        ->toBe('localhost:5000/acme/app@sha256:'.str_repeat('c', 64));
});

it('refuses an answer that is not a digest', function (): void {
    // A docker that printed a warning, an empty manifest, a changed --format.
    // Anything but a sha256 leaves the reference as it was.
    [$digests] = digester(new CommandResult(true, 0, '"latest"', ''));

    expect($digests->pin('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm'))
        ->toBe('ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm');
});
