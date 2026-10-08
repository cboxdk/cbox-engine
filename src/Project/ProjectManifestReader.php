<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Service\HealthCheck;
use Cbox\Platform\Service\SecretReference;
use Cbox\Platform\Service\SourceMount;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds a project's manifest and turns it into something typed.
 *
 * EVERY REFUSAL HAPPENS HERE, while the person who wrote the file is looking at
 * it. The compiler refuses the same things and so does the API server, but each
 * of those lands further away and in a vocabulary the developer never opted
 * into. The last of the three to reject something is the only one that is any
 * use as feedback.
 *
 * WALKS UPWARDS, because a developer runs commands from wherever they happen to
 * be — `app/Http`, a `tests` directory, anywhere. A tool that only works from
 * the repository root is a tool with an unstated precondition, and `cd ../..`
 * is not a thing anybody should have to think about.
 */
class ProjectManifestReader
{
    public const FILENAME = 'cbox.yaml';

    public function __construct(private readonly ?GithubToken $github = null) {}

    /**
     * Everything this file may say.
     *
     * Listed so a key nothing reads is refused rather than shrugged at — see
     * {@see self::guardKeys()}.
     *
     * @var list<string>
     */
    private const KEYS = [
        'build', 'domains', 'env', 'health', 'idle_seconds', 'image', 'mount', 'name', 'port',
        'mounts', 'processes', 'release', 'replicas', 'resources', 'scale_to_zero', 'secrets', 'services',
        'source', 'url',
    ];

    public function find(string $from): ?string
    {
        $directory = realpath($from);

        if ($directory === false) {
            return null;
        }

        while (true) {
            $candidate = $directory.DIRECTORY_SEPARATOR.self::FILENAME;

            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($directory);

            // dirname('/') is '/', so this is the top rather than a loop.
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    public function read(string $path): ProjectManifest
    {
        if (! is_file($path)) {
            throw new RuntimeException("There is no [{$path}] to read.");
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new RuntimeException('This is not valid YAML: '.$e->getMessage());
        }

        if (! is_array($parsed)) {
            throw new RuntimeException(
                self::FILENAME.' should describe one application, as a mapping of settings.'
            );
        }

        $this->guardKeys($parsed, self::KEYS, 'This file');

        $name = $this->name($parsed);
        $domains = $this->domains($parsed, $name);

        // A project always has a domain here, because one is defaulted — so the
        // only way to reach this without one is to write `domains: []`, and
        // scale-to-zero would then be a setting that silently does nothing.
        if ($this->scaleToZero($parsed) && $domains === []) {
            throw new RuntimeException(
                "[{$name}] asks to scale to zero and answers on no hostname, so nothing could ever "
                .'wake it. The wake is a request arriving; without a route there is no request.'
            );
        }

        $env = $this->env($parsed);
        $resources = $this->resources($parsed);
        $urlVariable = $this->urlVariable($parsed);
        $secrets = $this->secrets($parsed, $env, $resources, $urlVariable);

        return new ProjectManifest(
            name: $name,
            image: $this->image($parsed, $name),
            port: $this->port($parsed),
            domains: $domains,
            env: $env,
            processes: $this->processes($parsed),
            replicas: $this->replicas($parsed),
            resources: $resources,
            scaleToZero: $this->scaleToZero($parsed),
            idleSeconds: $this->idleSeconds($parsed, $name),
            urlVariable: $urlVariable,
            fromSource: $this->fromSource($parsed, $name),
            mountPath: $this->mountPath($parsed),
            mounts: $this->mounts($parsed, dirname($path)),
            build: BuildSpec::fromManifest($parsed['build'] ?? null, dirname($path), $this->github),
            services: SidecarService::listFrom($parsed['services'] ?? null),
            secrets: $secrets,
            health: $this->health($parsed, $secrets, $env),
            release: $this->release($parsed),
        );
    }

    /**
     * Environment variables from Secrets that already exist on the platform.
     *
     *     secrets:
     *       APP_KEY: { secret: cbox-id-app, key: APP_KEY }
     *
     * A NAME, NEVER A VALUE. This file is committed; what it can say about a
     * credential is where it lives. The Secret is the operator's, created
     * before the first deploy — nothing here creates, changes or removes it,
     * and a missing one stops the pod at start (`CreateContainerConfigError`)
     * rather than letting it boot without the value.
     *
     * ONE VARIABLE, ONE SOURCE. A name under both `env` and `secrets`, or one a
     * resource binds, reaches the container twice and Kubernetes keeps the last
     * — one of the two silently ignored, and which one depends on an order this
     * file does not show.
     *
     * @param  array<mixed>  $parsed
     * @param  array<string, string>  $env
     * @param  list<ResourceSpec>  $resources
     * @return list<SecretReference>
     */
    private function secrets(array $parsed, array $env, array $resources, string $urlVariable): array
    {
        $secrets = $parsed['secrets'] ?? [];

        if (! is_array($secrets)) {
            throw new RuntimeException(
                '`secrets` maps an environment variable to the Secret that holds it, like '
                .'`APP_KEY: { secret: myapp, key: APP_KEY }`.'
            );
        }

        $bound = [];

        foreach ($resources as $resource) {
            foreach ($resource->map as $variable) {
                $bound[$variable] = $resource->name;
            }
        }

        $references = [];

        foreach ($secrets as $variable => $source) {
            if (! is_string($variable) || ! SecretReference::isVariableName($variable)) {
                throw new RuntimeException(
                    '['.$variable.'] under `secrets` is not an environment variable name. Use letters, '
                    .'digits and underscores, not starting with a digit.'
                );
            }

            if (! is_array($source)) {
                throw new RuntimeException(
                    "[{$variable}] under `secrets` names where its value lives: "
                    ."`{$variable}: { secret: <Secret name>, key: <key in it> }`. Never the value itself — "
                    .'this file is committed.'
                );
            }

            $this->guardKeys($source, ['key', 'secret'], "[{$variable}] under `secrets`");

            $secret = $source['secret'] ?? null;
            $key = $source['key'] ?? null;

            if (! is_string($secret) || ! SecretReference::isSecretName($secret)) {
                throw new RuntimeException(
                    "[{$variable}] under `secrets` needs the `secret` it lives in: a Secret name of "
                    .'lower-case letters, digits, hyphens and dots, as it exists on the platform.'
                );
            }

            if (! is_string($key) || ! SecretReference::isKey($key)) {
                throw new RuntimeException(
                    "[{$variable}] under `secrets` needs the `key` inside [{$secret}] that holds it: "
                    .'letters, digits, \'-\', \'_\' and \'.\'.'
                );
            }

            if (array_key_exists($variable, $env)) {
                throw new RuntimeException(
                    "[{$variable}] is under both `env` and `secrets`. A container keeps one of the two "
                    .'and silently drops the other — remove the one you did not mean.'
                );
            }

            if (isset($bound[$variable])) {
                throw new RuntimeException(
                    "[{$variable}] is under `secrets` and is also bound by the resource [{$bound[$variable]}]. "
                    .'The binding already supplies it from the platform\'s own Secret — remove one.'
                );
            }

            if ($variable === $urlVariable) {
                throw new RuntimeException(
                    "[{$variable}] is the variable `url` fills in with this project's address, so it "
                    .'cannot also come from a Secret.'
                );
            }

            $references[] = new SecretReference($variable, $secret, $key);
        }

        return $references;
    }

    /**
     * The web process's probes.
     *
     *     health:
     *       path: /health/ready      # readiness — decides traffic
     *       liveness: /up            # liveness — decides restarts
     *       token: HEALTH_TOKEN      # optional: sent to `path` as a bearer token
     *
     * THE TOKEN IS A NAME, and it has to be one of `secrets`. A probe header
     * cannot come from a Secret in Kubernetes, so the platform has the container
     * ask itself with the variable it already holds — which only works if the
     * variable is there, and a token written under `env` is a credential
     * committed to the repository.
     *
     * @param  array<mixed>  $parsed
     * @param  list<SecretReference>  $secrets
     * @param  array<string, string>  $env
     */
    private function health(array $parsed, array $secrets, array $env): ?HealthCheck
    {
        $health = $parsed['health'] ?? null;

        if ($health === null) {
            return null;
        }

        if (! is_array($health)) {
            throw new RuntimeException(
                '`health` says where the platform asks whether a pod can serve (`path`) and whether it '
                .'is alive (`liveness`), like `path: /health/ready` and `liveness: /up`.'
            );
        }

        $this->guardKeys($health, ['liveness', 'path', 'token'], '`health`');

        $path = $health['path'] ?? '';
        $liveness = $health['liveness'] ?? '';
        $token = $health['token'] ?? '';

        foreach (['path' => $path, 'liveness' => $liveness] as $field => $value) {
            if (! is_string($value) || ($value !== '' && ! HealthCheck::isPath($value))) {
                throw new RuntimeException(
                    "`health.{$field}` is an absolute path such as /health/ready — letters, digits and "
                    .'. _ ~ % / + : @ = & ? , - only, with no spaces or quotes.'
                );
            }
        }

        if (! is_string($path) || ! is_string($liveness)) {
            throw new RuntimeException('`health` takes paths.');
        }

        if ($path === '' && $liveness === '') {
            throw new RuntimeException('`health` needs a readiness `path`, a `liveness` path, or both.');
        }

        if (! is_string($token) || ($token !== '' && ! SecretReference::isVariableName($token))) {
            throw new RuntimeException(
                '`health.token` names the environment variable that holds the token, such as HEALTH_TOKEN — '
                .'never the token itself.'
            );
        }

        if ($token !== '' && $path === '') {
            throw new RuntimeException(
                '`health.token` is sent to the readiness `path`, and there is none. Liveness never takes '
                .'a token: it has to answer even when the application cannot.'
            );
        }

        if ($token !== '' && array_key_exists($token, $env)) {
            throw new RuntimeException(
                "`health.token` is [{$token}], which is under `env` — a credential written into a committed "
                .'file. Move it under `secrets`, as a reference to the Secret that holds it.'
            );
        }

        $declared = array_map(static fn (SecretReference $reference): string => $reference->variable, $secrets);

        if ($token !== '' && ! in_array($token, $declared, true)) {
            throw new RuntimeException(
                "`health.token` is [{$token}], and nothing under `secrets` sets it. Without it the probe "
                .'sends an empty token, is refused, and the pod never becomes ready.'
            );
        }

        return new HealthCheck(readinessPath: $path, livenessPath: $liveness, tokenVariable: $token);
    }

    /**
     * The command that runs to completion, once per release, before anything rolls.
     *
     *     release: php artisan migrate --force
     *
     * A command, parsed like a process's — a string or a list, never a shell.
     * The deploy runs it as a Job in the new release's container, waits for it,
     * and stops with the running release untouched if it fails.
     *
     * @param  array<mixed>  $parsed
     * @return list<string>
     */
    private function release(array $parsed): array
    {
        $release = $parsed['release'] ?? null;

        if ($release === null) {
            return [];
        }

        // A LIST, not any array: `release: {command: migrate}` would otherwise
        // be read as the command `migrate` with its key thrown away, which is a
        // setting quietly meaning something other than what it says.
        if (is_array($release) && (! array_is_list($release) || array_filter($release, is_string(...)) !== $release)) {
            throw new RuntimeException(
                '`release` is a command line, or a list of arguments — `["php", "artisan", "migrate", "--force"]`. '
                .'This one has a setting nothing reads, or an argument that is not text.'
            );
        }

        if (! is_string($release) && ! is_array($release)) {
            throw new RuntimeException(
                '`release` is the command that runs before every deploy rolls out, such as '
                .'`php artisan migrate --force` — a command line, or a list of arguments.'
            );
        }

        return CommandLine::parse($release, 'release');
    }

    /**
     * @param  array<mixed>  $parsed
     */
    private function name(array $parsed): string
    {
        $name = $parsed['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('Give the application a `name`. Everything else is named after it.');
        }

        $name = trim($name);

        // The same rule the compiler enforces, said here where it can be fixed.
        // A name that fails this reaches Kubernetes as an object it refuses,
        // and the message names a field rather than a line in a file.
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) !== 1 || strlen($name) > 40) {
            throw new RuntimeException(
                "[{$name}] cannot be a project name. Use lower-case letters, digits and hyphens, "
                .'start and end with a letter or digit, and keep it under 40 characters — it becomes '
                .'a namespace, a hostname and every object inside them.'
            );
        }

        return $name;
    }

    /**
     * @param  array<mixed>  $parsed
     */
    private function image(array $parsed, string $name): string
    {
        $image = $parsed['image'] ?? null;

        if (is_string($image) && trim($image) !== '') {
            return trim($image);
        }

        // A PROJECT THAT BUILDS ITS OWN has no image to name yet — the tag
        // carries the built image's id, which cannot exist before the build.
        // The deployer fills it in, so a placeholder here would be a value
        // nothing reads and a value somebody could mistake for a default.
        if (($parsed['build'] ?? null) !== null && ($parsed['build'] ?? null) !== false) {
            return '';
        }

        throw new RuntimeException(
            "[{$name}] has no `image` and no `build`. Name an image to run, or write `build: true` "
            .'to build the Dockerfile beside this file.'
        );
    }

    /**
     * @param  array<mixed>  $parsed
     */
    private function port(array $parsed): int
    {
        $port = $parsed['port'] ?? 8080;

        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('`port` is the port your application listens on, between 1 and 65535.');
        }

        return $port;
    }

    /**
     * Extra directories from this machine, at explicit container paths.
     *
     * Written the way somebody reads it — container path first, because that is
     * where the thing ENDS UP and the question being answered is "what is at
     * /var/www/html/vendor/acme/pkg":
     *
     *     mounts:
     *       /var/www/html/vendor/acme/pkg: ../..
     *
     * The host side is relative to this file, for the same reason `build` is: a
     * path resolved against the shell's working directory is one that is found
     * on Tuesday and missing on Wednesday.
     *
     * @param  array<mixed>  $parsed
     * @return list<SourceMount>
     */
    private function mounts(array $parsed, string $directory): array
    {
        $mounts = $parsed['mounts'] ?? null;

        if ($mounts === null) {
            return [];
        }

        if (! is_array($mounts)) {
            throw new RuntimeException(
                '`mounts` maps a path inside the container to a directory here, like '
                .'`/var/www/html/vendor/acme/pkg: ../..`.',
            );
        }

        $specs = [];

        foreach ($mounts as $container => $host) {
            if (! is_string($container) || ! str_starts_with($container, '/') || ! is_string($host)) {
                throw new RuntimeException(
                    'Every entry under `mounts` is an absolute container path mapped to a directory.',
                );
            }

            $path = str_starts_with($host, '/') ? $host : rtrim($directory, '/').'/'.$host;
            $resolved = realpath($path);

            if ($resolved === false) {
                throw new RuntimeException("There is nothing at [{$path}] to mount at [{$container}].");
            }

            $specs[] = new SourceMount($resolved, rtrim($container, '/'));
        }

        return $specs;
    }

    /**
     * Where the working copy is mounted, and where the image serves from.
     *
     * `/var/www/html` is the Cbox base image's answer and the default. It is
     * NOT everybody's: one real application's image works from `/var/www` and serves
     * `/var/www/public-api`, and a platform that insisted on its own path would
     * mount the application somewhere its own nginx does not look — an empty
     * document root, and no error anywhere.
     *
     * @param  array<mixed>  $parsed
     */
    private function mountPath(array $parsed): string
    {
        $mount = $parsed['mount'] ?? null;

        if ($mount === null) {
            return '/var/www/html';
        }

        if (! is_string($mount) || ! str_starts_with($mount, '/')) {
            throw new RuntimeException(
                '`mount` is the absolute path inside the container where your code should appear, '
                .'such as /var/www/html.',
            );
        }

        return rtrim($mount, '/');
    }

    /**
     * Whether this project runs from the working copy rather than from an image.
     *
     * @param  array<mixed>  $parsed
     */
    private function fromSource(array $parsed, string $name): bool
    {
        $source = $parsed['source'] ?? null;

        if ($source === null) {
            return false;
        }

        if (! is_bool($source)) {
            throw new RuntimeException(
                '`source` is yes or no: whether this project runs from the files next to '
                .self::FILENAME.', rather than from a built image. It takes no path — the path is '
                .'wherever this file is, and a path written into a repository is wrong in every '
                .'other checkout.',
            );
        }

        $runtime = ($parsed['image'] ?? null) !== null
            || (($parsed['build'] ?? null) !== null && ($parsed['build'] ?? null) !== false);

        if ($source && ! $runtime) {
            throw new RuntimeException(
                "[{$name}] runs from source, so something has to run it: name an `image` — a Cbox "
                .'base image such as `ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.4-bookworm` — or '
                .'write `build: true` to build the Dockerfile beside this file.',
            );
        }

        return $source;
    }

    /**
     * Which variable should be told the project's own address.
     *
     * @param  array<mixed>  $parsed
     */
    private function urlVariable(array $parsed): string
    {
        $url = $parsed['url'] ?? null;

        if ($url === null) {
            return '';
        }

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException(
                '`url` names the environment variable your application reads its own address from, '
                .'for example `url: APP_URL`.',
            );
        }

        return trim($url);
    }

    /**
     * @param  array<mixed>  $parsed
     * @return list<string>
     */
    private function domains(array $parsed, string $name): array
    {
        $domains = $parsed['domains'] ?? null;

        // ABSENT AND EMPTY ARE DIFFERENT ANSWERS, and conflating them takes a
        // shape away from the developer.
        //
        // Absent means they did not think about it, and a hostname is how
        // anybody looks at what they just deployed — so one is given. Writing
        // `domains: []` is thinking about it and saying none: a project of
        // workers and schedulers that serves nothing, which is an ordinary thing
        // to have and cannot be expressed if an empty list is quietly refilled.
        if ($domains === null) {
            return [ProjectManifest::defaultDomain($name)];
        }

        if (! is_array($domains)) {
            throw new RuntimeException('`domains` is a list of hostnames.');
        }

        $clean = [];

        foreach ($domains as $domain) {
            if (! is_string($domain) || trim($domain) === '') {
                throw new RuntimeException('Every entry under `domains` has to be a hostname.');
            }

            $clean[] = trim($domain);
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $parsed
     * @return array<string, string>
     */
    private function env(array $parsed): array
    {
        $env = $parsed['env'] ?? [];

        if (! is_array($env)) {
            throw new RuntimeException('`env` is a mapping of names to values.');
        }

        $clean = [];

        foreach ($env as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Every name under `env` has to be text.');
            }

            // Numbers and booleans are written unquoted all the time, and
            // refusing them would be pedantry: an environment variable is a
            // string by definition, so they are made into one. What is refused
            // is a structure, which has no meaning as a value.
            if (is_bool($value)) {
                $clean[$key] = $value ? 'true' : 'false';

                continue;
            }

            // Numbers written unquoted are ordinary and refusing them would be
            // pedantry — an environment variable is text by definition, so they
            // are made into text. A structure is refused, because it has no
            // meaning as a value.
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new RuntimeException("[{$key}] under `env` has to be a single value.");
            }

            $clean[$key] = (string) $value;
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $parsed
     * @return array<string, list<string>>
     */
    private function processes(array $parsed): array
    {
        $processes = $parsed['processes'] ?? [];

        if (! is_array($processes)) {
            throw new RuntimeException('`processes` is a mapping of names to commands.');
        }

        $clean = [];

        foreach ($processes as $name => $command) {
            if (! is_string($name) || trim($name) === '') {
                throw new RuntimeException('Every process needs a name.');
            }

            if (! is_string($command) && ! is_array($command)) {
                throw new RuntimeException(
                    "The command for [{$name}] has to be a command line, or a list of arguments."
                );
            }

            $clean[trim($name)] = CommandLine::parse($command, trim($name));
        }

        return $clean;
    }

    /**
     * Whether this project may go to nothing when nobody is using it.
     *
     * REQUIRES A DOMAIN, and refusing here is the difference between a setting
     * that does nothing and a developer who thinks it does. The wake is
     * request-triggered: an interceptor holds the request open while the pod
     * starts, so a project nothing routes to has nothing to wake it, and the
     * compiler quietly emits the ordinary shape instead.
     *
     * @param  array<mixed>  $parsed
     */
    private function scaleToZero(array $parsed): bool
    {
        $value = $parsed['scale_to_zero'] ?? false;

        if (! is_bool($value)) {
            throw new RuntimeException('`scale_to_zero` is true or false.');
        }

        return $value;
    }

    /**
     * How long without a request before it goes.
     *
     * @param  array<mixed>  $parsed
     */
    private function idleSeconds(array $parsed, string $name): int
    {
        $value = $parsed['idle_seconds'] ?? 300;

        if (! is_int($value) || $value < 10) {
            throw new RuntimeException(
                "`idle_seconds` for [{$name}] is how many seconds without a request before it "
                .'scales away, and at least 10 — anything shorter spends more time starting than '
                .'running.'
            );
        }

        return $value;
    }

    /**
     * Refuse a key nothing reads.
     *
     * A SHRUG IS THE WORST ANSWER HERE. `map:` instead of `bind:` produced a
     * resource bound to `CACHE_HOST` when the application reads `REDIS_HOST` —
     * a workload that starts, connects to nothing, and says only
     * `Connection refused` from somewhere deep in a vendor directory. Measured,
     * on a real application, and it took a pod inspection to find.
     *
     * Nothing in this file is so large that listing its keys is a burden, and
     * the alternative is a manifest where half of what somebody wrote is
     * silently doing nothing.
     *
     * @param  array<mixed>  $parsed
     * @param  list<string>  $allowed
     */
    private function guardKeys(array $parsed, array $allowed, string $what): void
    {
        $unknown = array_diff(array_keys($parsed), $allowed);

        if ($unknown === []) {
            return;
        }

        sort($allowed);

        throw new RuntimeException(
            $what.' has '.(count($unknown) === 1 ? 'a setting' : 'settings').' nothing reads: '
            .implode(', ', array_map(strval(...), $unknown)).'. It takes: '.implode(', ', $allowed).'.',
        );
    }

    /**
     * The databases and caches this project asks for.
     *
     * Written as a mapping of name to settings, so the name is the thing a
     * developer refers to everywhere else — in the binding, in the command that
     * opens a shell to it, in the one that takes a dump.
     *
     * @param  array<mixed>  $parsed
     * @return list<ResourceSpec>
     */
    private function resources(array $parsed): array
    {
        $resources = $parsed['resources'] ?? [];

        if (! is_array($resources)) {
            throw new RuntimeException('`resources` is a mapping of names to databases and caches.');
        }

        $specs = [];

        foreach ($resources as $name => $settings) {
            if (! is_string($name) || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
                throw new RuntimeException(
                    'Every resource needs a name of lower-case letters, digits and hyphens — it '
                    .'becomes objects in your cluster.'
                );
            }

            // `cache: valkey` is the whole of the common case, so it is allowed
            // to be the whole of what is written.
            $settings = is_string($settings) ? ['engine' => $settings] : $settings;

            if (! is_array($settings)) {
                throw new RuntimeException("The resource [{$name}] should be an engine, or a mapping of settings.");
            }

            $engineName = $settings['engine'] ?? null;

            if (! is_string($engineName) || trim($engineName) === '') {
                throw new RuntimeException("The resource [{$name}] needs an `engine`.");
            }

            $this->guardKeys($settings, ['engine', 'version', 'storage', 'bind'], "The resource [{$name}]");

            $engine = ResourceSpec::engineFrom($engineName);

            $specs[] = new ResourceSpec(
                name: $name,
                engine: $engine,
                version: $this->resourceVersion($settings, $engine),
                storage: $this->resourceStorage($settings, $name),
                map: $this->resourceMap($settings, $engine, $name),
            );
        }

        return $specs;
    }

    /**
     * @param  array<mixed>  $settings
     */
    private function resourceVersion(array $settings, DatabaseEngine $engine): string
    {
        $version = $settings['version'] ?? null;

        if ($version === null) {
            // A default that is stated rather than "latest": a database whose
            // major version moves under a project between two machines is the
            // opposite of what this product is for.
            return match ($engine) {
                DatabaseEngine::Postgres => '17',
                DatabaseEngine::Percona => '8.0',
                DatabaseEngine::Valkey => '8',
            };
        }

        if (! is_string($version) && ! is_int($version) && ! is_float($version)) {
            throw new RuntimeException('A resource `version` is text, like "17" or "8.0".');
        }

        return (string) $version;
    }

    /**
     * @param  array<mixed>  $settings
     */
    private function resourceStorage(array $settings, string $name): string
    {
        $storage = $settings['storage'] ?? '1Gi';

        if (! is_string($storage) || preg_match('/^\d+(Mi|Gi|Ti)$/', $storage) !== 1) {
            throw new RuntimeException(
                "The `storage` for [{$name}] is a size like 512Mi, 1Gi or 10Gi."
            );
        }

        return $storage;
    }

    /**
     * @param  array<mixed>  $settings
     * @return array<string, string>
     */
    private function resourceMap(array $settings, DatabaseEngine $engine, string $name): array
    {
        $bind = $settings['bind'] ?? null;

        if ($bind === null) {
            return ResourceSpec::defaultMap($engine, $name);
        }

        if (! is_array($bind)) {
            throw new RuntimeException(
                "The `bind` for [{$name}] maps connection fields to environment variables, "
                .'like `host: DB_HOST`.'
            );
        }

        $map = [];

        foreach ($bind as $field => $variable) {
            if (! is_string($field) || ! is_string($variable) || trim($variable) === '') {
                throw new RuntimeException("Every entry in `bind` for [{$name}] maps a field to a variable name.");
            }

            if (ConnectionField::tryFrom($field) === null) {
                throw new RuntimeException(
                    "[{$field}] is not a connection field. Use host, port, database, user, password or url."
                );
            }

            // Refused here rather than dropped later. A Valkey has no password
            // Secret — the platform deploys it without one — so binding a
            // password produced a workload mounting something nothing creates,
            // and a pod stuck in CreateContainerConfigError beside a cache that
            // was running perfectly. Silently ignoring it would leave a
            // developer whose application sends AUTH to a server with none.
            if ($field === 'password' && $engine === DatabaseEngine::Valkey) {
                throw new RuntimeException(
                    "[{$name}] is a Valkey, which this platform deploys without a password, so there "
                    .'is nothing to bind to. Remove the `password` line.'
                );
            }

            $map[$field] = $variable;
        }

        return $map;
    }

    /**
     * @param  array<mixed>  $parsed
     */
    private function replicas(array $parsed): int
    {
        $replicas = $parsed['replicas'] ?? 1;

        if (! is_int($replicas) || $replicas < 1) {
            throw new RuntimeException('`replicas` is how many copies of the web process to run, at least 1.');
        }

        return $replicas;
    }
}
