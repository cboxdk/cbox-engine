<?php

declare(strict_types=1);

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\BuildSpec;
use Cbox\Engine\Project\GithubToken;
use Cbox\Engine\Project\ImageBuilder;
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Project\SidecarCompiler;
use Cbox\Engine\Project\SidecarService;
use Cbox\Engine\Sandbox\SandboxBuilder;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\CommandResult;
use Illuminate\Support\Facades\Artisan;

/*
 * The applications that do not run on a base image.
 *
 * One application here brings a Dockerfile with its own nginx, redis and supervisor on
 * debian-slim, and a compose file with seven services. A platform whose answer
 * is "port it first" is one nobody ports anything to.
 */

it('reads a build block relative to the manifest, not the shell', function (): void {
    // `cbox deploy` runs from wherever somebody happens to be — that is the
    // point of walking up to find the file — so a Dockerfile resolved against
    // the cwd is one that is found on Tuesday and missing on Wednesday.
    $root = sys_get_temp_dir().'/cbox-build-'.getmypid();
    @mkdir($root.'/docker', 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");
    file_put_contents($root.'/docker/Other', "FROM scratch\n");

    // The path as the filesystem really spells it: `/var` is a symlink to
    // `/private/var` on a Mac, and the context is resolved so docker and the
    // developer are talking about the same directory.
    $root = (string) realpath($root);

    $default = BuildSpec::fromManifest(true, $root);
    $named = BuildSpec::fromManifest(['dockerfile' => 'docker/Other'], $root);

    expect($default?->dockerfile)->toBe($root.'/Dockerfile')
        ->and($named?->dockerfile)->toBe($root.'/docker/Other')
        ->and(BuildSpec::fromManifest(['target' => 'app', 'args' => ['V' => 2]], $root))
        ->toHaveProperty('target', 'app')
        ->and(BuildSpec::fromManifest(null, $root))->toBeNull()
        // Refused here rather than by docker, whose message about a build
        // context sends somebody looking at the wrong thing.
        ->and(fn () => BuildSpec::fromManifest(['dockerfile' => 'Nope'], $root))
        ->toThrow(RuntimeException::class, 'There is no Dockerfile');
});

it('tags the built image with its own id, so a rebuild actually rolls out', function (): void {
    // A fixed tag means the pod spec never changes, so a rebuilt image never
    // reaches a node and every edit appears to do nothing. Learned on Cortex,
    // where a fixed CAPI template name meant exactly that.
    $runner = new FakeCommandRunner;
    $root = (string) realpath(sys_get_temp_dir()).'/cbox-build-'.getmypid();
    @mkdir($root, 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");

    $ok = new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: '');

    $runner->stage(['docker', 'build', '-t', 'cbox-local/acme:building', '-f', $root.'/Dockerfile', $root], $ok)
        ->stage(
            ['docker', 'image', 'inspect', 'cbox-local/acme:building', '--format', '{{.Id}}'],
            new CommandResult(ran: true, exitCode: 0, errorOutput: '', output: "sha256:abcdef0123456789aaaa\n"),
        )
        ->stage(['docker', 'tag', 'cbox-local/acme:building', 'cbox-local/acme:abcdef0123456789'], $ok)
        ->stage(['kind', 'load', 'docker-image', 'cbox-local/acme:abcdef0123456789', '--name', 'cbox'], $ok);

    /** @var BuildSpec $spec */
    $spec = BuildSpec::fromManifest(true, $root);

    $reference = (new ImageBuilder($runner))->build($spec, 'acme');

    expect($reference)->toBe('cbox-local/acme:abcdef0123456789');
});

it('says the image did not build rather than deploying one that does not exist', function (): void {
    $runner = new FakeCommandRunner;
    $root = (string) realpath(sys_get_temp_dir()).'/cbox-build-'.getmypid();
    @mkdir($root, 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");

    /** @var BuildSpec $spec */
    $spec = BuildSpec::fromManifest(true, $root);

    expect(fn () => (new ImageBuilder($runner))->build($spec, 'acme'))
        ->toThrow(RuntimeException::class, 'did not build');
});

it('compiles a sidecar to a deployment and a service, and nothing more', function (): void {
    // No volume, no backup, no scaling, no promises. The moment a sidecar
    // persists, somebody has real data in a container nothing backs up.
    $services = SidecarService::listFrom([
        'mailpit' => 'axllent/mailpit:v1.21',
        'clickhouse' => ['image' => 'clickhouse/clickhouse-server:24.8', 'port' => 8123, 'env' => ['X' => 1]],
    ]);

    $documents = (new SidecarCompiler)->compile($services, 'cbox-acme', 'acme');
    $kinds = array_map(static fn ($d): string => $d->kind(), $documents);

    // Mailpit named no port, so it gets no Service — there is nothing to reach.
    expect($kinds)->toBe(['Deployment', 'Deployment', 'Service'])
        ->and(collect($documents)->every(fn ($d): bool => $d->labels()['platform.cbox.dk/managed'] === 'true'))
        ->toBeTrue();

    $body = json_decode((string) json_encode($documents[1]->body), true);

    expect(data_get($body, 'spec.template.spec.containers.0.env.0'))->toBe(['name' => 'X', 'value' => '1'])
        // The selector is the sidecar's name ALONE: a Deployment's selector is
        // immutable, and one carrying the project could never be renamed.
        ->and(data_get($body, 'spec.selector.matchLabels'))->toBe(['cbox.dk/sidecar' => 'clickhouse']);
});

it('refuses a service name that cannot be a hostname', function (): void {
    expect(fn () => SidecarService::listFrom(['Click_House' => 'x']))
        ->toThrow(RuntimeException::class, 'has to be a DNS label')
        ->and(fn () => SidecarService::listFrom(['ch' => ['port' => 1]]))
        ->toThrow(RuntimeException::class, 'needs an `image`');
});

it('deploys the sidecars alongside the application', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--no-wait' => true,
        '--path' => projectAt(
            "name: acme\nimage: acme/web:1\nservices:\n  mailpit:\n    image: axllent/mailpit:v1.21\n    port: 1025\n",
        ),
    ]);

    expect(documentNamed($kubernetes->applied, 'Deployment', 'mailpit'))->not->toBeNull()
        ->and(documentNamed($kubernetes->applied, 'Service', 'mailpit'))->not->toBeNull();
});

it('mounts the working copy where the image actually serves from', function (): void {
    // one real application's image works from /var/www and serves /var/www/public-api.
    // A platform that insisted on /var/www/html would mount the application
    // where its nginx does not look: an empty document root, and no error.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    Artisan::call('local:deploy', [
        '--no-wait' => true,
        '--path' => projectAt("name: acme\nimage: acme/web:1\nsource: true\nmount: /var/www\n"),
    ]);

    $deployment = decoded($kubernetes->applied, 'Deployment', 'acme');

    expect(data_get($deployment, 'spec.template.spec.containers.0.volumeMounts.0.mountPath'))->toBe('/var/www');
});

it('takes a build credential from the shell and refuses when it is missing', function (): void {
    // One real application needs a NODE_AUTH_TOKEN for a private npm registry.
    // A token written into cbox.yaml is a token committed to a repository, so
    // the manifest names the variable and the value comes from the environment.
    //
    // Refused when unset rather than passed through empty: measured, an empty
    // token produced `401 Unauthorized` five minutes into a build, which reads
    // like a broken registry rather than a missing variable.
    $root = (string) realpath(sys_get_temp_dir()).'/cbox-build-'.getmypid();
    @mkdir($root, 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");

    putenv('CBOX_TEST_TOKEN=from-the-shell');

    $spec = BuildSpec::fromManifest(['args' => ['TOKEN' => '${CBOX_TEST_TOKEN}']], $root);

    expect($spec?->args)->toBe(['TOKEN' => 'from-the-shell']);

    putenv('CBOX_TEST_TOKEN');

    expect(fn () => BuildSpec::fromManifest(['args' => ['TOKEN' => '${CBOX_TEST_TOKEN}']], $root))
        ->toThrow(RuntimeException::class, 'is not set here');

    // A plain value is still a plain value.
    expect(BuildSpec::fromManifest(['args' => ['V' => '2']], $root)?->args)->toBe(['V' => '2']);
});

it('mounts a directory over the application, so an edit is live', function (): void {
    // The case a single source path cannot express: a package installed by
    // composer into a throwaway application and then overlaid by the
    // developer's real directory. INSIDE /var/www/html, because a symlink
    // pointing above the mount is outside the image's open_basedir and the
    // application dies including the package's own service provider.
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $root = projectAt("name: acme\nimage: acme/web:1\nsource: true\nmounts:\n  /var/www/html/vendor/acme/pkg: .\n");

    Artisan::call('local:deploy', ['--no-wait' => true, '--path' => $root]);

    $deployment = decoded($kubernetes->applied, 'Deployment', 'acme');

    /** @var list<array{mountPath: string}> $mounts */
    $mounts = data_get($deployment, 'spec.template.spec.containers.0.volumeMounts');

    // AFTER the application: a later mount shadows an earlier one, and that
    // order is the mechanism.
    expect(array_column($mounts, 'mountPath'))->toBe(['/var/www/html', '/var/www/html/vendor/acme/pkg']);
});

it('refuses a mount of something that is not there', function (): void {
    $kubernetes = new RecordingKubernetes;
    app()->instance(Kubernetes::class, $kubernetes);

    $exit = Artisan::call('local:deploy', [
        '--no-wait' => true,
        '--path' => projectAt("name: acme\nimage: acme/web:1\nsource: true\nmounts:\n  /var/www/html/x: ./nowhere\n"),
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('There is nothing at');
});

it('takes a GitHub Packages token from the developer\'s own gh login', function (): void {
    // That application's .npmrc reads `_authToken=${NODE_AUTH_TOKEN}` and nothing on a
    // developer's machine exports it, so the build died on a 401 several
    // minutes in. `gh` is signed in on any machine that cloned the repository.
    $root = (string) realpath(sys_get_temp_dir()).'/cbox-gh-'.getmypid();
    @mkdir($root, 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");
    file_put_contents($root.'/.npmrc', "@acme:registry=https://npm.pkg.github.com\n"
        ."//npm.pkg.github.com/:_authToken=\${NODE_AUTH_TOKEN}\n");

    $runner = (new FakeCommandRunner)->stage(
        ['gh', 'auth', 'token'],
        new CommandResult(true, 0, "gho_from-the-login\n", ''),
    );

    $spec = BuildSpec::fromManifest(
        ['args' => ['NODE_AUTH_TOKEN' => '${NODE_AUTH_TOKEN}']],
        $root,
        new GithubToken($runner),
    );

    expect($spec?->args)->toBe(['NODE_AUTH_TOKEN' => 'gho_from-the-login']);
});

it('refuses to hand a GitHub token to a registry that is not GitHub', function (): void {
    // The whole guard. A variable named the same thing, bound on an npmjs line,
    // is a different credential going somewhere else — and a tool that hands
    // the developer's GitHub token to whatever asked last is how one leaks.
    $root = (string) realpath(sys_get_temp_dir()).'/cbox-gh-other-'.getmypid();
    @mkdir($root, 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");
    file_put_contents($root.'/.npmrc', "# npm.pkg.github.com is used elsewhere in this file\n"
        ."//registry.npmjs.org/:_authToken=\${NODE_AUTH_TOKEN}\n");

    $runner = (new FakeCommandRunner)->stage(
        ['gh', 'auth', 'token'],
        new CommandResult(true, 0, "gho_from-the-login\n", ''),
    );

    expect(fn () => BuildSpec::fromManifest(
        ['args' => ['NODE_AUTH_TOKEN' => '${NODE_AUTH_TOKEN}']],
        $root,
        new GithubToken($runner),
    ))->toThrow(RuntimeException::class, 'gh auth login')
        ->and($runner->calls)->toBe([]);
});

it('leaves the shell in charge when it has an answer', function (): void {
    // A developer who exported something meant it — a token for a different
    // account, a short-lived one, a test value.
    $root = (string) realpath(sys_get_temp_dir()).'/cbox-gh-shell-'.getmypid();
    @mkdir($root, 0755, true);
    file_put_contents($root.'/Dockerfile', "FROM scratch\n");
    file_put_contents($root.'/.npmrc', "//npm.pkg.github.com/:_authToken=\${NODE_AUTH_TOKEN}\n");

    putenv('NODE_AUTH_TOKEN=exported-by-hand');

    $runner = new FakeCommandRunner;

    $spec = BuildSpec::fromManifest(
        ['args' => ['NODE_AUTH_TOKEN' => '${NODE_AUTH_TOKEN}']],
        $root,
        new GithubToken($runner),
    );

    putenv('NODE_AUTH_TOKEN');

    expect($spec?->args)->toBe(['NODE_AUTH_TOKEN' => 'exported-by-hand'])
        ->and($runner->calls)->toBe([]);
});

it('gives the manifest reader its token resolver when the container builds one', function (): void {
    // Shipped broken once. The dependency is nullable with a default, and the
    // container hands over the default rather than resolving the class — so
    // every unit test passed and the real `cbox deploy` refused a real
    // build with a message explaining how to fix something already fixed.
    $reader = app(ProjectManifestReader::class);

    $github = (new ReflectionClass($reader))->getProperty('github');
    $github->setAccessible(true);

    expect($github->getValue($reader))->toBeInstanceOf(GithubToken::class);
});

it('makes the sandbox directory ignore itself', function (): void {
    // A WHOLE LARAVEL APPLICATION GOES INTO SOMEBODY'S REPOSITORY. It is
    // disposable and the command says so — and it still turned up as untracked
    // in `git status`, a few thousand files a `git add -A` would commit. Found
    // on this package's own checkout by running the command.
    $package = sys_get_temp_dir().'/cbox-sandbox-ignore-'.getmypid();
    @mkdir($package.'/.cbox/sandbox/php84-laravel13', 0755, true);

    $builder = new SandboxBuilder(new FakeCommandRunner);

    $ignore = new ReflectionMethod($builder, 'ignoreItself');
    $ignore->invoke($builder, $package);

    expect(file_get_contents($package.'/.cbox/.gitignore'))->toContain('*');

    // Never overwritten: a developer who edited it meant to.
    file_put_contents($package.'/.cbox/.gitignore', 'mine');
    $ignore->invoke($builder, $package);

    expect(file_get_contents($package.'/.cbox/.gitignore'))->toBe('mine');
});
