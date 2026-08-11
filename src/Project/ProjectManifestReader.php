<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Database\DatabaseEngine;
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
        'build', 'domains', 'env', 'idle_seconds', 'image', 'mount', 'name', 'port',
        'mounts', 'processes', 'replicas', 'resources', 'scale_to_zero', 'services', 'source', 'url',
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

        return new ProjectManifest(
            name: $name,
            image: $this->image($parsed, $name),
            port: $this->port($parsed),
            domains: $domains,
            env: $this->env($parsed),
            processes: $this->processes($parsed),
            replicas: $this->replicas($parsed),
            resources: $this->resources($parsed),
            scaleToZero: $this->scaleToZero($parsed),
            idleSeconds: $this->idleSeconds($parsed, $name),
            urlVariable: $this->urlVariable($parsed),
            fromSource: $this->fromSource($parsed, $name),
            mountPath: $this->mountPath($parsed),
            mounts: $this->mounts($parsed, dirname($path)),
            build: BuildSpec::fromManifest($parsed['build'] ?? null, dirname($path), $this->github),
            services: SidecarService::listFrom($parsed['services'] ?? null),
        );
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
