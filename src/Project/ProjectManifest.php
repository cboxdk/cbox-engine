<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Platform\Binding\BindingSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Service\ProcessSpec;
use Cbox\Platform\Service\ServiceSpec;
use Cbox\Platform\Service\SourceMount;

/**
 * An application's own description of itself, read from a file in its repository.
 *
 * A FILE, and everything good about this design follows from that one choice: it
 * is diffable, it is reviewed alongside the code that needs it, a git worktree
 * gets its own copy for free, an agent can propose a change to it as an ordinary
 * patch, and "push to production" becomes "read this at that commit" rather than
 * a synchronisation mechanism somebody has to maintain.
 *
 * IT IS THE SAME INTENT PRODUCTION USES. This object exists only to turn a file
 * into `Cbox\Platform\Service\ServiceSpec` — the identical type Cortex builds
 * from database rows — so from the compiler down there is one path, one set of
 * golden tests, and nothing that can behave differently here.
 */
readonly class ProjectManifest
{
    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $domains
     * @param  array<string, list<string>>  $processes  name => argument list
     */
    public function __construct(
        public string $name,
        public string $image,
        public int $port,
        public array $domains,
        public array $env = [],
        public array $processes = [],
        public int $replicas = 1,
        /** @var list<ResourceSpec> */
        public array $resources = [],
        /**
         * Whether this project may go to nothing when nobody is using it.
         *
         * On a cell this is a cost decision. On a laptop it is what makes
         * installing every project you work on possible instead of only the two
         * you are working on today — an idle project holds no CPU and no memory
         * and comes back on the first request.
         */
        public bool $scaleToZero = false,
        public int $idleSeconds = 300,
        /**
         * Whether the whole project is put away.
         *
         * NOT IN THE MANIFEST FILE, and deliberately: sleeping is something a
         * developer does to their machine this afternoon, not a property of the
         * application that belongs in its repository and in everybody else's
         * checkout.
         */
        public bool $suspended = false,
        /**
         * Which copy of this project this is.
         *
         * NOT IN THE FILE either, and for the same reason as `suspended`: which
         * worktree somebody is standing in is a fact about their afternoon, not
         * about the application. It comes from git.
         */
        public Environment $environment = new Environment,
        /**
         * The directory this project's manifest was read from.
         *
         * Recorded so that an environment can be traced back to the worktree
         * that made it — which is what lets `cbox prune` tell a branch somebody
         * merged last week from one they are working in right now. Empty when
         * the manifest did not come from a directory, as in a test.
         */
        public string $path = '',
        /**
         * Which environment variable should carry this project's own address.
         *
         * NAMED, NEVER GUESSED — the same rule as bindings. A platform that
         * decides an application reads `APP_URL` has made a guess about a
         * framework, and the one that reads `BASE_URL` gets an application that
         * starts and generates links to somewhere else.
         *
         * It exists because an ENVIRONMENT MOVES THE HOSTNAME and nothing else
         * tells the application. A worktree deployed at
         * `feature-x.demo.cbox.test` whose `APP_URL` still says
         * `demo.cbox.test` writes that hostname into every password-reset mail,
         * every redirect and every absolute link — pointing at the environment
         * next door, with its own database.
         */
        public string $urlVariable = '',
        /**
         * Whether this project runs from the working copy on disk.
         *
         * NOT A PATH IN THE FILE, a yes or no: the path is wherever the manifest
         * was found, and a path written into a repository is one that is wrong
         * in every other checkout — including the worktree beside it.
         */
        public bool $fromSource = false,
        /**
         * How to build this project's own image, when it brings one.
         *
         * NOT EVERY APPLICATION RUNS ON A BASE IMAGE. Some applications have a Dockerfile
         * with its own nginx, redis and supervisor on debian-slim, and a
         * platform whose answer is "port it first" is one nobody ports anything
         * to.
         */
        public ?BuildSpec $build = null,
        /**
         * Services the platform does not model — ClickHouse, Kafka, Mailpit.
         *
         * Local only. What a production ClickHouse should be is not a decision
         * a development manifest gets to make by being pushed.
         *
         * @var list<SidecarService>
         */
        public array $services = [],
        /**
         * Where the working copy appears inside the container.
         *
         * The Cbox base image serves `/var/www/html`, and that is the default —
         * but it is not everybody's, and mounting an application where its own
         * nginx does not look is an empty document root with no error anywhere.
         */
        public string $mountPath = '/var/www/html',
        /**
         * Extra directories from this machine, at explicit container paths.
         *
         * For what a single source path cannot say: a package installed into a
         * throwaway application and then overlaid by the developer's real
         * directory, so an edit is live.
         *
         * @var list<SourceMount>
         */
        public array $mounts = [],
    ) {}

    /** The same project, put away or brought back. */
    public function withSuspended(bool $suspended): self
    {
        return new self(
            name: $this->name,
            image: $this->image,
            port: $this->port,
            domains: $this->domains,
            env: $this->env,
            processes: $this->processes,
            replicas: $this->replicas,
            resources: $this->resources,
            scaleToZero: $this->scaleToZero,
            idleSeconds: $this->idleSeconds,
            suspended: $suspended,
            environment: $this->environment,
            path: $this->path,
            urlVariable: $this->urlVariable,
            fromSource: $this->fromSource,
            build: $this->build,
            services: $this->services,
            mountPath: $this->mountPath,
            mounts: $this->mounts,
        );
    }

    /**
     * The same project, in an environment of its own.
     *
     * The domains move with it. Two environments answering on the same hostname
     * is not an ambiguity the gateway resolves in anybody's favour — it is one
     * route winning and a developer looking at the wrong environment's data.
     */
    public function in(Environment $environment): self
    {
        return new self(
            name: $this->name,
            image: $this->image,
            port: $this->port,
            domains: array_map($environment->hostname(...), $this->domains),
            env: $this->env,
            processes: $this->processes,
            replicas: $this->replicas,
            resources: $this->resources,
            scaleToZero: $this->scaleToZero,
            idleSeconds: $this->idleSeconds,
            suspended: $this->suspended,
            environment: $environment,
            path: $this->path,
            urlVariable: $this->urlVariable,
            fromSource: $this->fromSource,
            build: $this->build,
            services: $this->services,
            mountPath: $this->mountPath,
            mounts: $this->mounts,
        );
    }

    /**
     * The same project, running the image that was just built for it.
     *
     * The tag carries the image's ID, so this changes whenever the layers do —
     * which is what makes a rebuild actually roll out. A fixed tag means the pod
     * spec never changes and every edit appears to do nothing.
     */
    public function runningImage(string $image): self
    {
        return new self(
            name: $this->name,
            image: $image,
            port: $this->port,
            domains: $this->domains,
            env: $this->env,
            processes: $this->processes,
            replicas: $this->replicas,
            resources: $this->resources,
            scaleToZero: $this->scaleToZero,
            idleSeconds: $this->idleSeconds,
            suspended: $this->suspended,
            environment: $this->environment,
            path: $this->path,
            urlVariable: $this->urlVariable,
            fromSource: $this->fromSource,
            build: $this->build,
            services: $this->services,
            mountPath: $this->mountPath,
            mounts: $this->mounts,
        );
    }

    /** The same project, remembering where it was read from. */
    public function at(string $path): self
    {
        return new self(
            name: $this->name,
            image: $this->image,
            port: $this->port,
            domains: $this->domains,
            env: $this->env,
            processes: $this->processes,
            replicas: $this->replicas,
            resources: $this->resources,
            scaleToZero: $this->scaleToZero,
            idleSeconds: $this->idleSeconds,
            suspended: $this->suspended,
            environment: $this->environment,
            path: $path,
            urlVariable: $this->urlVariable,
            fromSource: $this->fromSource,
            build: $this->build,
            services: $this->services,
            mountPath: $this->mountPath,
            mounts: $this->mounts,
        );
    }

    /**
     * What this copy of the project is called on the cluster.
     *
     * Every name derives from this one — the namespace, the labels, the
     * certificate — so two environments of one project cannot collide anywhere
     * by construction rather than by remembering to qualify each place.
     */
    public function deployedName(): string
    {
        return $this->environment->qualify($this->name);
    }

    /**
     * Where this project's objects live.
     *
     * One namespace per project, which is how a cell separates tenants — so
     * what a developer learns about isolation locally is true there too.
     */
    public function namespace(): string
    {
        return 'cbox-'.$this->deployedName();
    }

    /**
     * The intent, in the shared vocabulary.
     *
     * `organizationId` is the machine, because there is exactly one: a laptop
     * has no tenancy to model, and inventing an identifier would put a value in
     * every label that means nothing to anybody reading it.
     *
     * `serviceId` is the project's NAME rather than an opaque id. It becomes
     * `app.kubernetes.io/instance`, so `kubectl -l` and every dashboard built on
     * that label read as the developer's own vocabulary instead of a ULID they
     * would have to look up.
     */
    /**
     * The databases this project asks for, as the shared model.
     *
     * @param  array<string, string>  $passwords  resource name => root password
     *
     * ONE INSTANCE, always, and not because a manifest cannot ask for more: the
     * compiler refuses more than one for the engines it schedules itself, and a
     * development machine has one node to put them on. A local target that
     * offered replicas would be offering a rehearsal of something that cannot
     * happen here.
     * @return list<DatabaseSpec>
     */
    public function toDatabaseSpecs(array $passwords = []): array
    {
        return array_map(fn (ResourceSpec $resource): DatabaseSpec => new DatabaseSpec(
            databaseId: $resource->name,
            organizationId: 'local',
            namespace: $this->namespace(),
            name: $resource->name,
            engine: $resource->engine,
            version: $resource->version,
            instances: 1,
            storageSize: $resource->storage,
            suspended: $this->suspended,
            // Only where the engine needs one, and read back from the cluster
            // when it is already there: a new password on every deploy is a
            // password the data directory was not initialised with.
            password: $passwords[$resource->name] ?? null,
        ), $this->resources);
    }

    /**
     * @param  array<string, ConnectionSource>  $sources  resource name => where its details live
     */
    public function toServiceSpec(array $sources = []): ServiceSpec
    {
        return new ServiceSpec(
            serviceId: $this->deployedName(),
            organizationId: 'local',
            namespace: $this->namespace(),
            name: $this->deployedName(),
            image: $this->image,
            port: $this->port,
            replicas: $this->replicas,
            env: $this->env,
            processes: array_values(array_map(
                static fn (string $name, array $command): ProcessSpec => new ProcessSpec(
                    name: $name,
                    command: $command,
                    replicas: 1,
                ),
                array_keys($this->processes),
                array_values($this->processes),
            )),
            domains: $this->domains,
            bindings: $this->bindings($sources),
            scaleToZero: $this->scaleToZero,
            idleTimeoutSeconds: $this->idleSeconds,
            suspended: $this->suspended,
            // The directory the manifest was found in. The compiler translates
            // it into the node's view of the same place.
            sourcePath: $this->fromSource ? $this->path : '',
            appMountPath: $this->mountPath,
            mounts: $this->mounts,
            // RUNNING FROM SOURCE MEANS THE IMAGE IS A RUNTIME, which is what
            // `baseImage` means: the container is the image and the application
            // arrives separately — from disk here, from an image volume on a
            // cell. Saying so is not cosmetic. The compiler sets
            // `NGINX_TRUSTED_PROXIES` only for a service it knows is running on
            // a Cbox base image, and without it nginx trusts nobody: the
            // application reads the GATEWAY's address as its client on every
            // request. That is the exact bug class this product exists to
            // surface, and it was producing it instead.
            baseImage: $this->fromSource ? $this->image : '',
            // Which addresses may speak for a client. A property of the CLUSTER,
            // which is why it is here and not in anybody's manifest.
            podCidr: ClusterConfig::POD_CIDR,
        );
    }

    /**
     * How each resource's details reach the application, by NAME.
     *
     * Explicit rather than injected. A platform that decides which environment
     * variables an application reads has made a guess about a framework, and a
     * developer whose application wants `DB_HOST` where the platform wrote
     * `DATABASE_HOST` gets an application that starts and cannot connect.
     *
     * @param  array<string, ConnectionSource>  $sources
     * @return list<BindingSpec>
     */
    private function bindings(array $sources): array
    {
        $bindings = [];

        foreach ($this->resources as $resource) {
            if (! isset($sources[$resource->name])) {
                continue;
            }

            $map = [];

            foreach ($resource->map as $field => $variable) {
                $connectionField = ConnectionField::tryFrom($field);

                if ($connectionField !== null) {
                    $map[] = ['field' => $connectionField, 'name' => $variable];
                }
            }

            $bindings[] = new BindingSpec(
                databaseName: $resource->name,
                engine: $resource->engine->value,
                map: $map,
                source: $sources[$resource->name],
            );
        }

        return $bindings;
    }

    /**
     * The same project, told what it is called from outside.
     *
     * THE COMPUTED VALUE WINS over one written in `env`, and that is the point
     * of naming the variable at all: in an environment the declared value is
     * the OTHER environment's address, and honouring it would be honouring a
     * line the developer wrote before this copy existed.
     *
     * A wildcard is skipped. `*.demo.cbox.test` is a hostname a browser can
     * reach and not one anybody can be told to use.
     */
    public function withResolvedUrl(HostPorts $ports): self
    {
        $addressable = array_values(array_filter(
            $this->domains,
            static fn (string $domain): bool => ! str_starts_with($domain, '*'),
        ));

        if ($this->urlVariable === '' || $addressable === []) {
            return $this;
        }

        return new self(
            name: $this->name,
            image: $this->image,
            port: $this->port,
            domains: $this->domains,
            env: [...$this->env, $this->urlVariable => $ports->url($addressable[0])],
            processes: $this->processes,
            replicas: $this->replicas,
            resources: $this->resources,
            scaleToZero: $this->scaleToZero,
            idleSeconds: $this->idleSeconds,
            suspended: $this->suspended,
            environment: $this->environment,
            path: $this->path,
            urlVariable: $this->urlVariable,
            fromSource: $this->fromSource,
            build: $this->build,
            services: $this->services,
            mountPath: $this->mountPath,
            mounts: $this->mounts,
        );
    }

    /** The hostname a project answers on when it names none of its own. */
    public static function defaultDomain(string $name): string
    {
        return $name.'.'.ClusterObjects::DOMAIN;
    }
}
