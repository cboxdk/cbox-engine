<?php

declare(strict_types=1);

use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Platform\LocalTarget;
use Cbox\Engine\Project\ProjectDeployer;
use Cbox\Engine\Project\ProjectHealth;
use Cbox\Engine\Project\ProjectManifest;
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Project\ReleaseRunner;
use Cbox\Engine\Push\PushPayload;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ApplyOutcome;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Manifest\ManifestSet;

/*
 * Three keys a production manifest needs: `secrets` (an environment variable
 * from a Secret the operator created), `health` (the web process's probes) and
 * `release` (a command that runs to completion before anything rolls).
 */

const PRODUCTION = <<<'YAML'
name: shop
image: ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-v1
port: 80
url: APP_URL
replicas: 2
processes:
  queue: ["php", "artisan", "queue:work"]
env:
  APP_ENV: production
resources:
  database:
    engine: postgres
    bind:
      host: DB_HOST
      password: DB_PASSWORD
secrets:
  APP_KEY: { secret: shop-app, key: APP_KEY }
  HEALTH_TOKEN: { secret: shop-app, key: HEALTH_TOKEN }
  MAIL_PASSWORD: { secret: shop-mail, key: password }
health:
  path: /health/ready
  liveness: /up
  token: HEALTH_TOKEN
release: ["php", "artisan", "migrate", "--force"]
YAML;

function readProduction(string $yaml = PRODUCTION): ProjectManifest
{
    $root = sys_get_temp_dir().'/cbox-production-'.getmypid().'-'.md5($yaml);
    @mkdir($root, 0755, true);
    file_put_contents($root.'/'.ProjectManifestReader::FILENAME, $yaml);

    return (new ProjectManifestReader)->read($root.'/'.ProjectManifestReader::FILENAME);
}

/** The production manifest with one top-level key replaced (or removed, with null). */
function productionWith(string $key, ?string $block, string $yaml = PRODUCTION): string
{
    $lines = explode("\n", $yaml);
    $out = [];
    $skipping = false;

    foreach ($lines as $line) {
        if (preg_match('/^([a-z_]+):/', $line, $m) === 1) {
            $skipping = $m[1] === $key;

            if ($skipping && $block !== null) {
                $out[] = $block;
            }
        }

        if (! $skipping) {
            $out[] = $line;
        }
    }

    return implode("\n", $out)."\n";
}

/** @return array<string, array<string, mixed>> */
function containerEnv(ManifestSet $set, string $deployment): array
{
    $found = $set->find('Deployment/'.$deployment);

    return collect($found?->body['spec']['template']['spec']['containers'][0]['env'] ?? [])->keyBy('name')->all();
}

// ── the reader ───────────────────────────────────────────────────────────────

it('reads secrets, health and release from a production manifest', function (): void {
    $manifest = readProduction();

    expect(array_map(fn ($r): array => [$r->variable, $r->secret, $r->key], $manifest->secrets))->toBe([
        ['APP_KEY', 'shop-app', 'APP_KEY'],
        ['HEALTH_TOKEN', 'shop-app', 'HEALTH_TOKEN'],
        ['MAIL_PASSWORD', 'shop-mail', 'password'],
    ])
        ->and($manifest->health?->readinessPath)->toBe('/health/ready')
        ->and($manifest->health?->livenessPath)->toBe('/up')
        ->and($manifest->health?->tokenVariable)->toBe('HEALTH_TOKEN')
        ->and($manifest->release)->toBe(['php', 'artisan', 'migrate', '--force']);
});

it('reads a release written as a command line', function (): void {
    expect(readProduction(productionWith('release', 'release: php artisan migrate --force'))->release)
        ->toBe(['php', 'artisan', 'migrate', '--force']);
});

it('reads a manifest without any of the three exactly as before', function (): void {
    $manifest = readProduction("name: acme\nimage: acme/web:1\n");

    expect($manifest->secrets)->toBe([])
        ->and($manifest->health)->toBeNull()
        ->and($manifest->release)->toBe([]);
});

it('refuses a secrets entry it cannot reference safely', function (string $block, string $message): void {
    // Health without a token, so the secret under test is the only thing wrong.
    expect(fn () => readProduction(productionWith('secrets', $block, productionWith('health', "health:\n  path: /ready"))))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'a value instead of a reference' => ["secrets:\n  APP_KEY: base64:abc", 'Never the value itself'],
    'a list' => ["secrets:\n  - APP_KEY", 'not an environment variable name'],
    'a variable with a hyphen' => ["secrets:\n  APP-KEY: { secret: shop-app, key: K }", 'not an environment variable name'],
    'no secret' => ["secrets:\n  APP_KEY: { key: K }", 'needs the `secret` it lives in'],
    'an upper-case secret' => ["secrets:\n  APP_KEY: { secret: Shop, key: K }", 'needs the `secret` it lives in'],
    'no key' => ["secrets:\n  APP_KEY: { secret: shop-app }", 'needs the `key`'],
    'a key with a slash' => ["secrets:\n  APP_KEY: { secret: shop-app, key: a/b }", 'needs the `key`'],
    'a setting nothing reads' => ["secrets:\n  APP_KEY: { secret: shop-app, key: K, optional: true }", 'nothing reads: optional'],
    'also under env' => ["secrets:\n  APP_ENV: { secret: shop-app, key: APP_ENV }", 'under both `env` and `secrets`'],
    'also bound by a resource' => ["secrets:\n  DB_PASSWORD: { secret: shop-db, key: password }", 'also bound by the resource [database]'],
    'the url variable' => ["secrets:\n  APP_URL: { secret: shop-app, key: APP_URL }", 'cannot also come from a Secret'],
]);

it('refuses a health check that could not work', function (string $block, string $message): void {
    expect(fn () => readProduction(productionWith('health', $block)))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'a string' => ['health: /up', 'whether a pod can serve'],
    'no paths' => ["health:\n  token: HEALTH_TOKEN", 'needs a readiness `path`'],
    'a relative path' => ["health:\n  path: health/ready", 'is an absolute path'],
    'a quote in the path' => ["health:\n  path: \"/ready'x\"", 'is an absolute path'],
    'a setting nothing reads' => ["health:\n  path: /ready\n  readiness: /ready", 'nothing reads: readiness'],
    'the token itself' => ["health:\n  path: /ready\n  token: s3cr3t-value!", 'never the token itself'],
    'a token without readiness' => ["health:\n  liveness: /up\n  token: HEALTH_TOKEN", 'there is none'],
    'a token nothing sets' => ["health:\n  path: /ready\n  token: OTHER_TOKEN", 'nothing under `secrets` sets it'],
    'a token under env' => ["health:\n  path: /ready\n  token: APP_ENV", 'Move it under `secrets`'],
]);

it('refuses a release that is not a command', function (string $block, string $message): void {
    expect(fn () => readProduction(productionWith('release', $block)))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'a mapping' => ["release:\n  command: migrate", 'nothing reads'],
    'a number' => ['release: 3', 'the command that runs before every deploy'],
    'a shell line' => ['release: php artisan migrate && php artisan cache:clear', 'only means something to a shell'],
    'nothing' => ['release: ""', 'no command to run'],
]);

// ── what it compiles to ─────────────────────────────────────────────────────

it('references every secret from every container, and never copies a value', function (): void {
    $set = new ServiceCompiler(new LocalTarget()->make(new HostPorts(80, 443, 53)))
        ->compile(readProduction()->toServiceSpec());

    foreach (['shop', 'shop-queue'] as $deployment) {
        $env = containerEnv($set, $deployment);

        expect($env['APP_KEY']['valueFrom']['secretKeyRef'])->toBe(['name' => 'shop-app', 'key' => 'APP_KEY'])
            ->and($env['MAIL_PASSWORD']['valueFrom']['secretKeyRef'])->toBe(['name' => 'shop-mail', 'key' => 'password']);
    }

    // The operator's Secrets are theirs: none is compiled, so none is ever
    // overwritten or pruned by a deploy.
    expect(collect($set->manifests)->where('kind', 'Secret')->pluck('name')->all())
        ->not->toContain('shop-app')
        ->not->toContain('shop-mail');
});

it('probes the web process only, with the token read inside the container', function (): void {
    $set = new ServiceCompiler(new LocalTarget()->make(new HostPorts(80, 443, 53)))
        ->compile(readProduction()->toServiceSpec());

    $web = $set->find('Deployment/shop')?->body['spec']['template']['spec']['containers'][0] ?? [];
    $queue = $set->find('Deployment/shop-queue')?->body['spec']['template']['spec']['containers'][0] ?? [];

    expect($web['livenessProbe']['httpGet'])->toBe(['path' => '/up', 'port' => 'http'])
        ->and($web['startupProbe']['httpGet'])->toBe(['path' => '/up', 'port' => 'http'])
        ->and($web['readinessProbe']['exec']['command'][2])->toContain('"${HEALTH_TOKEN}"')
        ->and($web['readinessProbe']['exec']['command'][2])->toContain("'http://127.0.0.1:80/health/ready'")
        ->and($queue)->not->toHaveKeys(['readinessProbe', 'livenessProbe', 'startupProbe']);
});

it('says what a push leaves behind', function (): void {
    $omitted = implode("\n", new PushPayload(readProduction())->omitted());

    expect($omitted)->toContain('3 secret references')
        ->toContain('health')
        ->toContain('php artisan migrate --force');
});

// ── the deploy: the release first, the workloads after ─────────────────────

/**
 * A cluster whose database is ready and whose Jobs end the way a test says.
 */
function releaseCluster(string $ending = 'Complete', ?string $existing = null): RecordingKubernetes
{
    return new class($ending, $existing) extends RecordingKubernetes
    {
        /** @var list<string> */
        public array $reads = [];

        private bool $jobApplied = false;

        public function __construct(private string $ending, private ?string $existing) {}

        public function apply(array $manifests, bool $dryRun = false): ApplyOutcome
        {
            foreach ($manifests as $manifest) {
                if ($manifest->kind() === 'Job' && ! $dryRun) {
                    $this->jobApplied = true;
                }
            }

            return parent::apply($manifests, $dryRun);
        }

        public function delete(string $kind, string $name, string $namespace): bool
        {
            if ($kind === 'job') {
                $this->existing = null;
            }

            return parent::delete($kind, $name, $namespace);
        }

        public function read(string $kind, string $name, string $namespace): ?ManifestDocument
        {
            $this->reads[] = $kind.'/'.$name;

            if ($kind === 'cluster') {
                return ManifestDocument::fromArray([
                    'kind' => 'Cluster', 'metadata' => ['name' => $name],
                    'spec' => ['instances' => 1], 'status' => ['readyInstances' => 1],
                ]);
            }

            if ($kind === 'job') {
                $condition = $this->jobApplied ? $this->ending : $this->existing;

                return $condition === null || $condition === 'Running' ? ($this->jobApplied ? ManifestDocument::fromArray(['kind' => 'Job']) : null) : ManifestDocument::fromArray([
                    'kind' => 'Job', 'metadata' => ['name' => $name],
                    'status' => ['conditions' => [['type' => $condition, 'status' => 'True']]],
                ]);
            }

            return parent::read($kind, $name, $namespace);
        }
    };
}

function deployWithRelease(RecordingKubernetes $kubernetes, bool $dryRun = false, int $polls = 10): ApplyOutcome
{
    return new ProjectDeployer(
        $kubernetes,
        new LocalTarget,
        releases: new ReleaseRunner($kubernetes, new ProjectHealth($kubernetes), interval: 1, polls: $polls, pause: static function (int $seconds): void {}),
    )->deploy(readProduction(), $dryRun);
}

/** @return list<string> the applies, in order, with the release Job's hash stripped */
function applyOrder(RecordingKubernetes $kubernetes): array
{
    return array_values(array_map(
        static fn (string $event): string => preg_replace('/-release-[0-9a-f]{10}$/', '-release', $event) ?? $event,
        array_filter($kubernetes->events, static fn (string $event): bool => str_starts_with($event, 'apply Deployment')
            || str_starts_with($event, 'apply Job')
            || str_starts_with($event, 'apply Cluster')
            || str_starts_with($event, 'delete job')),
    ));
}

it('runs the release to completion before a single workload rolls', function (): void {
    $kubernetes = releaseCluster('Complete');

    $outcome = deployWithRelease($kubernetes);

    expect($outcome->succeeded)->toBeTrue()
        // The database and everything else first, the Job alone, and only then
        // the Deployments — with the database applied again in the full set,
        // which server-side apply makes a no-op.
        ->and(applyOrder($kubernetes))->toBe([
            'apply Cluster/database',
            'apply Job/shop-release',
            'apply Cluster/database',
            'apply Deployment/shop',
            'apply Deployment/shop-queue',
        ])
        // It waited for the database before running a migration against it.
        ->and(array_search('cluster/database', $kubernetes->reads, true))
        ->toBeLessThan(array_search('job/'.collect($kubernetes->applied)->first(fn ($d) => $d->kind() === 'Job')->name(), $kubernetes->reads, true));
});

it('rolls nothing when the release fails, and says what it printed', function (): void {
    $kubernetes = releaseCluster('Failed');
    $kubernetes->logLine = 'SQLSTATE[42P07]: relation "users" already exists';

    $outcome = deployWithRelease($kubernetes);

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->failure)->toContain('The release `php artisan migrate --force` failed')
        ->and($outcome->failure)->toContain('the running release is untouched')
        ->and($outcome->failure)->toContain('relation "users" already exists')
        // The previous release's Deployments were never touched.
        ->and(applyOrder($kubernetes))->toBe(['apply Cluster/database', 'apply Job/shop-release']);
});

it('stops at once when the release pod can never start', function (): void {
    $kubernetes = releaseCluster('Running');
    $kubernetes->listedBySelector = [];
    $pod = ManifestDocument::fromArray([
        'kind' => 'Pod',
        'metadata' => ['name' => 'shop-release-x', 'namespace' => 'cbox-shop'],
        'status' => ['containerStatuses' => [['state' => ['waiting' => [
            'reason' => 'CreateContainerConfigError',
            'message' => 'secret "shop-mail" not found',
        ]]]]],
    ]);
    $kubernetes->listed = [$pod];

    $outcome = deployWithRelease($kubernetes);

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->failure)->toContain('never started')
        ->and($outcome->failure)->toContain('secret "shop-mail" not found')
        // Taken away, so the next deploy — after the Secret exists — starts it afresh.
        ->and(applyOrder($kubernetes))->toBe(['apply Cluster/database', 'apply Job/shop-release', 'delete job/shop-release']);
});

it('does not migrate twice for a release that already ran', function (): void {
    $kubernetes = releaseCluster('Complete', existing: 'Complete');

    $outcome = deployWithRelease($kubernetes);

    expect($outcome->succeeded)->toBeTrue()
        ->and(applyOrder($kubernetes))->toBe([
            'apply Cluster/database',
            'apply Cluster/database',
            'apply Deployment/shop',
            'apply Deployment/shop-queue',
        ]);
});

it('retries a release that failed last time, instead of reporting the old failure', function (): void {
    $kubernetes = releaseCluster('Complete', existing: 'Failed');

    expect(deployWithRelease($kubernetes)->succeeded)->toBeTrue()
        ->and(applyOrder($kubernetes))->toBe([
            'apply Cluster/database',
            'delete job/shop-release',
            'apply Job/shop-release',
            'apply Cluster/database',
            'apply Deployment/shop',
            'apply Deployment/shop-queue',
        ]);
});

it('gives up after its deadline rather than waiting forever', function (): void {
    $kubernetes = releaseCluster('Running');

    $outcome = deployWithRelease($kubernetes, polls: 3);

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->failure)->toContain('did not finish in time')
        ->and(applyOrder($kubernetes))->not->toContain('apply Deployment/shop');
});

it('validates the release on a dry run and runs nothing', function (): void {
    $kubernetes = releaseCluster('Complete');

    $outcome = deployWithRelease($kubernetes, dryRun: true);

    expect($outcome->succeeded)->toBeTrue()
        // One apply, through admission, with the Job in it — and no waiting on
        // a Job that a dry run never creates.
        ->and(collect($kubernetes->applied)->contains(fn ($d) => $d->kind() === 'Job'))->toBeTrue()
        ->and($kubernetes->reads)->not->toContain('cluster/database');
});

it('deploys a project without a release in one apply, as before', function (): void {
    $kubernetes = releaseCluster('Complete');

    new ProjectDeployer($kubernetes, new LocalTarget)
        ->deploy(readProduction(productionWith('release', null)));

    expect(applyOrder($kubernetes))->toBe([
        'apply Cluster/database',
        'apply Deployment/shop',
        'apply Deployment/shop-queue',
    ]);
});
