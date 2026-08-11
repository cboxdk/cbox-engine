<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\Environment;
use Cbox\Engine\Project\EnvironmentRegistry;
use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\WorktreeEnvironment;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\CommandResult;
use Cbox\Engine\ValueObjects\DeployedEnvironment;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Illuminate\Support\Facades\Artisan;

/*
 * A worktree is an environment.
 *
 * The same model Cortex uses for a preview — its own namespace, its own
 * hostname, its own resources, nothing inherited — so what a developer learns
 * here is true there.
 */

function gitSaid(string $output): CommandResult
{
    return new CommandResult(ran: true, exitCode: 0, output: $output, errorOutput: '');
}

it('turns a branch into something that can be a hostname', function (): void {
    // `feature/JIRA-42_thing` is an ordinary branch and none of `/`, `_` or an
    // uppercase letter can appear in a hostname.
    expect(Environment::named('feature/JIRA-42_thing')->name)->toBe('feature-jira-42-thing')
        ->and(Environment::named('  Fix  ')->name)->toBe('fix')
        ->and(Environment::named('--x--')->name)->toBe('x')
        // One label of a longer name, and a label has 63 characters in total.
        ->and(Environment::named(str_repeat('a', 60))->name)->toHaveLength(40)
        // Truncation must not leave a trailing dash, which is not a legal label.
        ->and(Environment::named(str_repeat('ab-', 20))->name)->not->toEndWith('-')
        ->and(Environment::default()->isDefault())->toBeTrue();
});

it('puts the environment after any leading wildcard, and nowhere else', function (): void {
    // One rule, both shapes — and the project's own domain scheme stays
    // recognisable, which matters when somebody is reading five environments
    // trying to find theirs.
    $environment = Environment::named('feature-x');

    expect($environment->hostname('demo.cbox.test'))->toBe('feature-x.demo.cbox.test')
        ->and($environment->hostname('*.demo.cbox.test'))->toBe('*.feature-x.demo.cbox.test')
        ->and($environment->hostname('api.demo.cbox.test'))->toBe('feature-x.api.demo.cbox.test')
        // The ordinary case pays nothing.
        ->and(Environment::default()->hostname('demo.cbox.test'))->toBe('demo.cbox.test');
});

it('treats the main checkout as the default however it is branched', function (): void {
    // Naming the environment after the branch everywhere would mean switching
    // branches in the directory somebody works in every day silently builds them
    // a second environment, with a second database, empty.
    $runner = new FakeCommandRunner;
    $runner->stage(['git', '-C', '/repo', 'rev-parse', '--absolute-git-dir'], gitSaid("/repo/.git\n"))
        ->stage(['git', '-C', '/repo', 'branch', '--show-current'], gitSaid("feature/x\n"));

    expect((new WorktreeEnvironment($runner))->at('/repo')->isDefault())->toBeTrue();
});

it('is not fooled by a path whose case differs from the repository', function (): void {
    // MEASURED, on this machine: `~/Projects/Example` is the same directory as
    // `~/Projects/example` on a case-insensitive filesystem, and git answers
    // with its own spelling. Comparing the two paths made an ordinary project
    // into a phantom `master` environment with its own namespace and its own
    // empty database, and nothing said why.
    $runner = new FakeCommandRunner;
    $runner->stage(
        ['git', '-C', '/Users/dev/Projects/that application', 'rev-parse', '--absolute-git-dir'],
        gitSaid("/Users/dev/Projects/example-app/.git\n"),
    );

    expect((new WorktreeEnvironment($runner))->at('/Users/dev/Projects/that application')->isDefault())->toBeTrue();
});

it('names an environment after the worktree branch', function (): void {
    $runner = new FakeCommandRunner;
    $runner->stage(['git', '-C', '/wt', 'rev-parse', '--absolute-git-dir'], gitSaid("/repo/.git/worktrees/wt\n"))
        ->stage(['git', '-C', '/wt', 'branch', '--show-current'], gitSaid("feature/x\n"));

    expect((new WorktreeEnvironment($runner))->at('/wt')->name)->toBe('feature-x');
});

it('falls back to the directory when a worktree is on no branch', function (): void {
    // A worktree checked out at a commit still needs a name, and the directory
    // it lives in is the one the person chose.
    $runner = new FakeCommandRunner;
    $runner->stage(['git', '-C', '/wt', 'rev-parse', '--absolute-git-dir'], gitSaid("/repo/.git/worktrees/hotfix\n"))
        ->stage(['git', '-C', '/wt', 'branch', '--show-current'], gitSaid(''));

    expect((new WorktreeEnvironment($runner))->at('/wt')->name)->toBe('hotfix');
});

it('is the default environment where there is no git at all', function (): void {
    // Nothing is staged, so every call comes back as one that never ran.
    expect((new WorktreeEnvironment(new FakeCommandRunner))->at('/anywhere')->isDefault())->toBeTrue();
});

it('gives an environment its own namespace, name and hostnames', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--no-wait' => true,
        '--path' => projectAt("name: acme\nimage: acme/web:1\ndomains:\n  - acme.cbox.test\n"),
        '--env' => 'feature/x',
        '--json' => true,
    ]);

    /** @var array<string, mixed> $output */
    $output = json_decode(Artisan::output(), true);

    expect(data_get($output, 'namespace'))->toBe('cbox-acme-feature-x')
        ->and(data_get($output, 'domains'))->toBe(['feature-x.acme.cbox.test'])
        // Nothing inherited: its own namespace means its own databases, its own
        // secrets and its own volumes.
        ->and(documentNamed($kubernetes->applied, 'Certificate', 'acme-feature-x-wildcard'))->not->toBeNull();
});

it('does not take another environment certificate away when one is deployed', function (): void {
    // Found by raising a worktree environment beside its project: both were
    // keyed by the project's own name, so the second deploy replaced the first's
    // certificate — one certificate on the cluster where there should have been
    // two, and no error anywhere.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector['platform.cbox.dk/managed=true'] = [
        ManifestDocument::fromArray([
            'kind' => 'HTTPRoute',
            'metadata' => ['name' => 'acme', 'labels' => ['platform.cbox.dk/service' => 'acme']],
            'spec' => ['hostnames' => ['acme.cbox.test']],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--no-wait' => true,
        '--path' => projectAt("name: acme\nimage: acme/web:1\ndomains:\n  - acme.cbox.test\n"),
        '--env' => 'feature-x',
    ]);

    $gateway = decoded($kubernetes->applied, 'Gateway', 'cbox');

    expect(data_get($gateway, 'spec.listeners.1.tls.certificateRefs'))->toBe([
        ['name' => 'cbox-wildcard-tls'],
        ['name' => 'acme-wildcard-tls'],
        ['name' => 'acme-feature-x-wildcard-tls'],
    ]);
});

it('records the worktree it was deployed from, beside what it deployed', function (): void {
    // Without this there is nothing on the cluster that could ever say which
    // environments are orphaned, and `cbox prune` could not exist.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $path = projectAt("name: acme\nimage: acme/web:1\n");

    Artisan::call('local:deploy', [
        '--no-wait' => true, '--path' => $path, '--env' => 'feature-x']);

    $origin = decoded($kubernetes->applied, 'ConfigMap', ProjectDeployer::ORIGIN);

    expect(data_get($origin, 'metadata.namespace'))->toBe('cbox-acme-feature-x')
        ->and(data_get($origin, 'data'))->toBe([
            'project' => 'acme',
            'environment' => 'feature-x',
            // The path as the filesystem really spells it: `/var` on a Mac is a
            // symlink to `/private/var`, and a recorded path that does not
            // survive being looked at again is one `cbox prune` would call
            // orphaned the moment it was written.
            'worktree' => realpath($path),
        ]);
});

it('tells an application the address of the environment it is in', function (): void {
    // A worktree deployed at `feature-x.acme.cbox.test` whose APP_URL still says
    // `acme.cbox.test` writes that hostname into every password-reset mail,
    // every redirect and every absolute link — pointing at the environment next
    // door, with its own database.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--no-wait' => true,
        '--path' => projectAt(
            "name: acme\nimage: acme/web:1\nurl: APP_URL\n"
            ."env:\n  APP_URL: https://acme.cbox.test\n"
            ."domains:\n  - '*.acme.cbox.test'\n  - acme.cbox.test\n",
        ),
        '--env' => 'feature-x',
    ]);

    $deployment = decoded($kubernetes->applied, 'Deployment', 'acme-feature-x');

    /** @var list<array{name: string, value?: string}> $env */
    $env = data_get($deployment, 'spec.template.spec.containers.0.env');
    $urls = array_values(array_filter($env, static fn (array $v): bool => $v['name'] === 'APP_URL'));

    // The computed value wins over the one in the file, because naming the
    // variable is opting in — and a wildcard is not an address anybody can be
    // told to use, so it is skipped for the name below it.
    expect($urls)->toHaveCount(1)
        ->and($urls[0]['value'] ?? null)->toBe('https://feature-x.acme.cbox.test:18443');
});

it('says nothing about an address when the project did not name a variable', function (): void {
    // The same rule as bindings: a platform that decides an application reads
    // APP_URL has made a guess about a framework.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--no-wait' => true, '--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    $deployment = decoded($kubernetes->applied, 'Deployment', 'acme');

    /** @var list<array{name: string}> $env */
    $env = data_get($deployment, 'spec.template.spec.containers.0.env') ?? [];

    // Nothing at all, not merely no APP_URL: a project that named no variable
    // gets no variable, and an empty name is not an opt-out.
    expect(array_column($env, 'name'))->toBe([]);
});

it('never calls the default environment orphaned', function (): void {
    // A project moved from ~/Projects to ~/code has not been abandoned, and
    // deleting its database because a path changed would be the worst thing this
    // tool could do.
    $moved = new DeployedEnvironment('acme', '', 'cbox-acme', '/gone', present: false);
    $merged = new DeployedEnvironment('acme', 'feature-x', 'cbox-acme-feature-x', '/gone', present: false);
    $current = new DeployedEnvironment('acme', 'feature-y', 'cbox-acme-feature-y', '/here', present: true);

    expect($moved->orphaned())->toBeFalse()
        ->and($merged->orphaned())->toBeTrue()
        ->and($current->orphaned())->toBeFalse()
        ->and($merged->name())->toBe('acme-feature-x')
        ->and($moved->name())->toBe('acme');
});

it('reads environments off the cluster and ignores records that say nothing', function (): void {
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [
        ManifestDocument::fromArray([
            'kind' => 'ConfigMap',
            'metadata' => ['name' => ProjectDeployer::ORIGIN, 'namespace' => 'cbox-acme-feature-x'],
            'data' => ['project' => 'acme', 'environment' => 'feature-x', 'worktree' => '/definitely/not/here'],
        ]),
        // Another managed ConfigMap in a project's namespace. The label says
        // this platform put it there; only the NAME says it is this record, and
        // an application's own config is free to have a key called `worktree`.
        ManifestDocument::fromArray([
            'kind' => 'ConfigMap',
            'metadata' => ['name' => 'app-config', 'namespace' => 'cbox-acme'],
            'data' => ['project' => 'acme', 'environment' => '', 'worktree' => '/definitely/not/here'],
        ]),
        // Ours, but written before it recorded a worktree.
        ManifestDocument::fromArray([
            'kind' => 'ConfigMap',
            'metadata' => ['name' => ProjectDeployer::ORIGIN, 'namespace' => 'cbox-old'],
            'data' => ['project' => 'old'],
        ]),
    ];

    $environments = (new EnvironmentRegistry($kubernetes))->all();

    expect($environments)->toHaveCount(1)
        ->and($environments[0]->name())->toBe('acme-feature-x')
        ->and($environments[0]->present)->toBeFalse();
});

it('will not prune with nobody there to ask', function (): void {
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listed = [
        ManifestDocument::fromArray([
            'kind' => 'ConfigMap',
            'metadata' => ['name' => ProjectDeployer::ORIGIN, 'namespace' => 'cbox-acme-feature-x'],
            'data' => ['project' => 'acme', 'environment' => 'feature-x', 'worktree' => '/definitely/not/here'],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:prune', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('/definitely/not/here')
        ->and($kubernetes->deleted)->toBe([]);
});

it('prunes the namespace and puts the gateway back in step', function (): void {
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector['platform.cbox.dk/managed=true'] = [
        ManifestDocument::fromArray([
            'kind' => 'ConfigMap',
            'metadata' => ['name' => ProjectDeployer::ORIGIN, 'namespace' => 'cbox-acme-feature-x'],
            'data' => ['project' => 'acme', 'environment' => 'feature-x', 'worktree' => '/definitely/not/here'],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:prune', ['--force' => true]);

    expect($kubernetes->deleted)->toBe([
        'namespace/cbox-acme-feature-x',
        'certificate/acme-feature-x-wildcard',
        'secret/acme-feature-x-wildcard-tls',
    ]);
});

it('reports a deploy that applied and is not running', function (): void {
    // The worst answer this tool can give is `✓ deployed` for a pod that cannot
    // start. Found exactly that way: an image with no build for this machine's
    // architecture applied cleanly and sat in ImagePullBackOff, where only
    // `kubectl describe` would ever have said why.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector['platform.cbox.dk/managed=true'] = [
        ManifestDocument::fromArray([
            'kind' => 'Deployment',
            'metadata' => ['name' => 'acme', 'namespace' => 'cbox-acme', 'generation' => 2],
            'spec' => ['replicas' => 1],
            'status' => ['observedGeneration' => 2, 'replicas' => 1, 'updatedReplicas' => 1, 'readyReplicas' => 0],
        ]),
        ManifestDocument::fromArray([
            'kind' => 'Pod',
            'metadata' => ['name' => 'acme-1', 'namespace' => 'cbox-acme'],
            'status' => [
                'phase' => 'Pending',
                'containerStatuses' => [[
                    'state' => ['waiting' => [
                        'reason' => 'ErrImagePull',
                        'message' => 'no match for platform in manifest: not found',
                    ]],
                ]],
            ],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:deploy', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('is not running')
        // The API's own words read like a missing image rather than a missing
        // architecture, and the answer is not "check the tag".
        ->toContain("no build for this machine's architecture");
});

it('does not call a rollout finished while the previous pod is still up', function (): void {
    // MEASURED, and the reason `readyReplicas` alone is not enough: during a
    // rolling update the PREVIOUS pod is ready and about to be deleted, so a
    // deploy of an image that cannot even be pulled reported success one second
    // after applying — twice, before this was understood.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector['platform.cbox.dk/managed=true'] = [
        ManifestDocument::fromArray([
            'kind' => 'Deployment',
            'metadata' => ['name' => 'acme', 'namespace' => 'cbox-acme', 'generation' => 3],
            'spec' => ['replicas' => 1],
            'status' => [
                'observedGeneration' => 3,
                // Two pods: the new one, and the old one still serving.
                'replicas' => 2,
                'updatedReplicas' => 1,
                'readyReplicas' => 1,
            ],
        ]),
        ManifestDocument::fromArray([
            'kind' => 'Pod',
            'metadata' => ['name' => 'acme-new', 'namespace' => 'cbox-acme'],
            'status' => [
                'phase' => 'Pending',
                'containerStatuses' => [[
                    'state' => ['waiting' => ['reason' => 'ImagePullBackOff', 'message' => 'not found']],
                ]],
            ],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:deploy', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('The image could not be pulled');
});

it('ignores a pod that has already been asked to go', function (): void {
    // The pod of a previous rollout is still listed while it terminates, and it
    // carries the reason the deploy being REPLACED failed for.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector['platform.cbox.dk/managed=true'] = [
        ManifestDocument::fromArray([
            'kind' => 'Deployment',
            'metadata' => ['name' => 'acme', 'namespace' => 'cbox-acme', 'generation' => 4],
            'spec' => ['replicas' => 1],
            'status' => [
                'observedGeneration' => 4,
                'replicas' => 1,
                'updatedReplicas' => 1,
                'readyReplicas' => 1,
            ],
        ]),
        ManifestDocument::fromArray([
            'kind' => 'Pod',
            'metadata' => [
                'name' => 'acme-old',
                'namespace' => 'cbox-acme',
                'deletionTimestamp' => '2026-08-11T00:00:00Z',
            ],
            'status' => [
                'phase' => 'Running',
                'containerStatuses' => [[
                    'state' => ['waiting' => ['reason' => 'ImagePullBackOff', 'message' => 'the old failure']],
                ]],
            ],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:deploy', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('the old failure');
});

it('does not wait for pods a project deliberately has none of', function (): void {
    // A slept or scaled-to-zero project has every workload at zero replicas on
    // purpose. Waiting for a pod there hangs for the whole deadline and then
    // reports that a correct deploy did not work.
    $kubernetes = new RecordingKubernetes;
    $kubernetes->listedBySelector['platform.cbox.dk/managed=true'] = [
        ManifestDocument::fromArray([
            'kind' => 'Deployment',
            'metadata' => ['name' => 'acme', 'namespace' => 'cbox-acme'],
            'spec' => ['replicas' => 0],
            'status' => [],
        ]),
    ];
    app()->instance(Kubernetes::class, $kubernetes);

    $started = microtime(true);
    $exit = Artisan::call('local:deploy', ['--path' => projectAt("name: acme\nimage: acme/web:1\n")]);

    expect($exit)->toBe(0)
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});
