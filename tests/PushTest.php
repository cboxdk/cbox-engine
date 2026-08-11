<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * Sending the definition to production.
 *
 * The manifest travels. The environment does not — and the things that must NOT
 * travel are the point of these tests, because each of them is silent when it
 * goes wrong and lands on a production service.
 */

beforeEach(function (): void {
    putenv('CBOX_CORTEX_URL=https://cortex.example.com');
    putenv('CBOX_TOKEN=test-token');
});

afterEach(function (): void {
    putenv('CBOX_CORTEX_URL');
    putenv('CBOX_TOKEN');
});

/**
 * @param  list<array{id: string, name: string}>  $services
 */
function cortexAnswers(array $services = []): void
{
    Http::fake([
        '*/v1/projects' => Http::response(['data' => [['id' => 'prj_1', 'name' => 'acme']]]),
        '*/v1/projects/prj_1/environments' => Http::response(['data' => [['id' => 'env_1', 'name' => 'production']]]),
        '*/v1/environments/env_1/services' => Http::sequence()
            ->push(['data' => $services])
            ->push(['data' => ['id' => 'svc_1', 'name' => 'acme']], 201),
        '*/v1/services/*/plan' => Http::response(['data' => ['changes' => [['kind' => 'Deployment']]]]),
        '*/v1/services/*' => Http::response(['data' => ['id' => 'svc_1']]),
    ]);
}

it('never sends the local hostname or the local address', function (): void {
    // The single most damaging pair of values that could travel: a hostname
    // nothing outside this laptop resolves, and an APP_URL pointing at it —
    // which would have a production application generating links to somebody's
    // machine.
    cortexAnswers();

    Artisan::call('local:push', [
        '--path' => projectAt(
            "name: acme\nimage: acme/web:1\nsource: true\nurl: APP_URL\n"
            ."env:\n  APP_URL: https://acme.cbox.test\n  MAIL_FROM: hi@acme.test\n"
            ."domains:\n  - acme.cbox.test\n",
        ),
        '--project' => 'acme',
        '--environment' => 'production',
    ]);

    /** @var array<string, mixed>|null $posted */
    $posted = null;

    Http::assertSent(function (Request $request) use (&$posted): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/environments/env_1/services')) {
            $posted = $request->data();
        }

        return true;
    });

    expect($posted)->not->toBeNull();

    /** @var array<string, mixed> $sent */
    $sent = $posted;

    /** @var array<string, string> $env */
    $env = $sent['env'] ?? [];

    expect($sent)->not->toHaveKey('domains')
        ->and($env)->not->toHaveKey('APP_URL')
        // What the developer actually declared still travels.
        ->and($env['MAIL_FROM'] ?? null)->toBe('hi@acme.test')
        ->and($sent['name'] ?? null)->toBe('acme');
});

it('says what it left behind', function (): void {
    // A push that quietly drops half a manifest is one somebody believes carried
    // all of it, and they find out when the production service answers on no
    // hostname.
    cortexAnswers();

    Artisan::call('local:push', [
        '--path' => projectAt(
            "name: acme\nimage: acme/web:1\nsource: true\nurl: APP_URL\n"
            ."domains:\n  - acme.cbox.test\n"
            ."resources:\n  db: postgres\n"
            ."processes:\n  queue: ['php','artisan','queue:work']\n",
        ),
    ]);

    $output = Artisan::output();

    expect($output)->toContain('1 local hostname')
        ->toContain('APP_URL')
        ->toContain('source')
        ->toContain('1 resource')
        ->toContain('1 process')
        // And it is explicit that this changed nothing in production.
        ->toContain('Nothing has been applied');
});

it('updates a service that is already there instead of making a second one', function (): void {
    cortexAnswers([['id' => 'svc_1', 'name' => 'acme']]);

    Artisan::call('local:push', ['--path' => projectAt("name: acme\nimage: acme/web:2\n")]);

    expect(Artisan::output())->toContain('updated in Cortex');

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST'
        || ! str_ends_with($request->url(), '/v1/environments/env_1/services'));
});

it('refuses to push a scale-to-zero service with nowhere for a request to arrive', function (): void {
    // The wake IS a request arriving. Without a production hostname the service
    // would deploy and never come back up, and the local hostnames do not travel.
    cortexAnswers();

    $exit = Artisan::call('local:push', [
        '--path' => projectAt("name: acme\nimage: acme/web:1\nscale_to_zero: true\n"),
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('--domain');
});

it('will not guess which Cortex or whose token', function (): void {
    putenv('CBOX_CORTEX_URL');
    putenv('CBOX_TOKEN');
    Http::fake();

    $exit = Artisan::call('local:push', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('CBOX_CORTEX_URL');

    putenv('CBOX_CORTEX_URL=https://cortex.example.com');

    $exit = Artisan::call('local:push', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('CBOX_TOKEN');

    Http::assertNothingSent();
});

it('names the choices when there is more than one', function (): void {
    Http::fake([
        '*/v1/projects' => Http::response(['data' => [
            ['id' => 'prj_1', 'name' => 'acme'],
            ['id' => 'prj_2', 'name' => 'other'],
        ]]),
    ]);

    $exit = Artisan::call('local:push', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('acme, other');
});

it('says plainly when a token cannot write', function (): void {
    // 403 from an API is a status code; "that token can read but not write" is
    // something somebody can act on.
    Http::fake([
        '*/v1/projects' => Http::response(['data' => [['id' => 'prj_1', 'name' => 'acme']]]),
        '*/v1/projects/prj_1/environments' => Http::response(['data' => [['id' => 'env_1', 'name' => 'production']]]),
        '*/v1/environments/env_1/services' => Http::sequence()
            ->push(['data' => []])
            ->push(['message' => 'Forbidden'], 403),
    ]);

    $exit = Artisan::call('local:push', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('read but not write');
});

it('refuses in JSON when JSON was asked for', function (): void {
    // `--json` exists so an agent does not have to guess where the output
    // starts. A refusal in prose breaks that for exactly the case that matters:
    // the agent handles the happy path and then meets a parser error, unable to
    // tell "the cluster is down" from "the tool crashed".
    putenv('CBOX_CORTEX_URL');
    Http::fake();

    $exit = Artisan::call('local:push', [
        '--path' => projectAt("name: acme\nimage: acme/web:1\n"),
        '--json' => true,
    ]);

    /** @var array<string, mixed>|null $document */
    $document = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($document)->toBeArray()
        ->and($document['error'] ?? null)->toContain('CBOX_CORTEX_URL');
});
