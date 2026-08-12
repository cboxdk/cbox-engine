<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Platform\LocalTarget;
use Cbox\Engine\Platform\ProjectListeners;
use Cbox\Engine\ValueObjects\ApplyOutcome;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Cbox\Platform\Compile\BackupCompiler;
use Cbox\Platform\Compile\CnpgDatabaseCompiler;
use Cbox\Platform\Compile\EngineDatabaseCompiler;
use Cbox\Platform\Compile\ServiceCompiler;
use Cbox\Platform\Compile\StatefulDatabaseCompiler;
use Cbox\Platform\Manifest\Manifest;

/**
 * A project's manifest, compiled and applied.
 *
 * The shortest path in the product, and deliberately: a file becomes typed
 * intent, the SHARED compiler turns that into Kubernetes objects, and they are
 * applied. Nothing here decides what an object should look like — that is the
 * package's job, and it is the same job it does for a cell.
 */
class ProjectDeployer
{
    /** Where an environment records the directory it was deployed from. */
    public const ORIGIN = 'cbox-origin';

    public function __construct(
        private readonly Kubernetes $kubernetes,
        private readonly LocalTarget $target,
        private readonly ConnectionSourceFactory $connections = new ConnectionSourceFactory,
        private readonly ?DatabasePasswords $passwords = null,
        private readonly ProjectListeners $listeners = new ProjectListeners,
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?ImageBuilder $images = null,
        private readonly SidecarCompiler $sidecars = new SidecarCompiler,
        private readonly HostPorts $ports = new HostPorts(
            HostPorts::HIGH_HTTP,
            HostPorts::HIGH_HTTPS,
            HostPorts::HIGH_DNS,
        ),
        private readonly ?ImageDigest $digests = null,
    ) {}

    public function deploy(
        ProjectManifest $manifest,
        bool $dryRun = false,
        bool $recreate = false,
        ?callable $onBuild = null,
    ): ApplyOutcome {
        // A DRY RUN CANNOT CREATE THE NAMESPACE IT WOULD PUT THINGS IN, so on a
        // project that has never been deployed every namespaced object comes
        // back `namespaces "cbox-demo" not found` — five errors that describe
        // the dry run rather than the manifest, in front of somebody who was
        // asking whether their file is correct.
        //
        // Said once, plainly, instead. There is genuinely nothing to check yet:
        // nothing exists for this change to conflict with.
        if ($dryRun && ! $this->namespaceExists($manifest)) {
            return new ApplyOutcome(
                succeeded: true,
                applied: 0,
                output: '',
                failure: '',
            );
        }

        // The project's own address, before anything is compiled: an
        // environment moved its hostname and nothing else would tell it.
        $manifest = $manifest->withResolvedUrl($this->ports);

        // THE IMAGE FIRST, when the project brings its own. Applying a pod
        // spec that names an image nothing has built yet is a pod in
        // ImagePullBackOff and a deploy that reported success.
        //
        // Not on a dry run: building is minutes of work and writes layers to
        // the machine, which is not what "change nothing" means.
        if ($manifest->build !== null && ! $dryRun) {
            $manifest = $manifest->runningImage(
                ($this->images ?? new ImageBuilder(app(CommandRunner::class)))
                    ->build($manifest->build, $manifest->deployedName(), $onBuild),
            );
        }

        // THE TAG PINNED TO WHAT IT POINTS AT TODAY. A node keeps the layers it
        // first pulled for a tag — `IfNotPresent` is the default for anything
        // but `:latest` — so a rebuilt base image is invisible to a running
        // project no matter how many times it is redeployed. A digest also
        // CHANGES the pod spec, which is what makes the rollout happen at all.
        // Never on a dry run: it reaches a registry, and "change nothing" also
        // means "ask nobody".
        if (! $dryRun) {
            $pinned = ($this->digests ?? new ImageDigest(app(CommandRunner::class)))->pin($manifest->image);

            if ($pinned !== $manifest->image) {
                $manifest = $manifest->runningImage($pinned);
            }
        }

        // THE SIBLINGS THIS PROJECT IS LINKED TO. A composer path repository
        // installs a package as a symlink out of the project, which resolves on
        // the machine and dangles in the pod — the application boots under Herd
        // and the container dies naming a file that is plainly there. Mounted
        // alongside the source, so the link lands on something.
        if ($manifest->fromSource) {
            $linked = (new LinkedPackages)->forProject($manifest->path, $manifest->mountPath);

            if ($linked !== []) {
                $manifest = $manifest->alsoMounting($linked);
            }
        }

        $target = $this->target->make($this->ports);

        // THE DATABASES FIRST, and their connection details before the service
        // that reads them. A binding resolves to a `secretKeyRef` at a Secret in
        // the cluster rather than to a copied password — so the workload does
        // not need the value at compile time, only the name of where it lives.
        $databases = new EngineDatabaseCompiler(
            new CnpgDatabaseCompiler($target, new BackupCompiler($target)),
            new StatefulDatabaseCompiler($target, new BackupCompiler($target)),
        );

        $sources = [];
        $manifests = [];

        $passwords = $this->passwords?->forResources($manifest->resources, $manifest->namespace()) ?? [];

        foreach ($manifest->toDatabaseSpecs($passwords) as $database) {
            $manifests = [...$manifests, ...$databases->compile($database)->manifests];
        }

        foreach ($manifest->resources as $resource) {
            $sources[$resource->name] = $this->connections->forResource($resource, $manifest->namespace());
        }

        $compiled = new ServiceCompiler($target)->compile($manifest->toServiceSpec($sources));
        $manifests = [...$manifests, ...$compiled->manifests];

        $documents = array_map(
            static fn ($m): ManifestDocument => ManifestDocument::fromArray($m->body),
            $manifests,
        );

        // THE AUTHORITY'S KEY PAIR FIRST, and in this project's namespace.
        //
        // `Certificates::certificateAuthority()` expects it where the
        // environment is, so a certificate compiled for this project names an
        // Issuer that reads a Secret here. Without the copy the Issuer sits
        // `IssuerNotFound` and every certificate under it waits forever — which
        // looks exactly like cert-manager being broken.
        //
        // Copied rather than shared, because a Secret cannot be read across a
        // namespace and cert-manager will not follow one.
        //
        // Appended, not prepended: the applier puts namespaces first by kind, so
        // where this sits in the list does not matter — and putting it first is
        // exactly what broke the first deploy, with the Secret arriving before
        // the Namespace it lives in.
        $documents = [...$documents, ...$this->certificateAuthority($manifest)];

        // AND THE GATEWAY, because a TLS wildcard matches exactly one label.
        // `*.cbox.test` covers `demo.cbox.test` and does not cover
        // `api.demo.cbox.test` — not in the certificate, and not in a listener's
        // hostname. Measured: every depth answered over HTTP and only the first
        // over HTTPS.
        //
        // So each project gets a listener and a certificate for names below its
        // own, and the listener set is DERIVED from the projects that exist
        // rather than accumulated. An accumulated list keeps every project this
        // machine has ever run, and a Gateway carrying listeners for
        // certificates that were deleted stops programming altogether.
        $documents = [...$documents, ...$this->listeners->manifests($this->deployedProjects($manifest))];

        // AND A NOTE OF WHERE THIS CAME FROM. An environment is made by standing
        // in a worktree, and worktrees are deleted — usually the same afternoon
        // the branch is merged, and never with a thought for the namespace,
        // database and volume still sitting on the cluster. Without this there
        // is nothing on the cluster that could ever say which environments are
        // orphaned, and `cbox prune` could not exist.
        $documents = [...$documents, ...$this->origin($manifest)];

        // AND WHATEVER ELSE THE APPLICATION NEEDS. ClickHouse, Kafka, Mailpit —
        // things the platform does not model and should not pretend to.
        $documents = [...$documents, ...$this->sidecars->compile(
            $manifest->services,
            $manifest->namespace(),
            $manifest->deployedName(),
        )];

        // Asked for, never assumed. Recreating a workload is a brief outage,
        // and a tool that decides that on somebody's behalf because an apply was
        // inconvenient is a tool that will one day do it during a demonstration.
        if ($recreate && ! $dryRun) {
            $this->removeWorkloads($manifest, $compiled->manifests);
        }

        return $this->kubernetes->apply($documents, $dryRun);
    }

    /**
     * Delete the workloads so the next apply can create them afresh.
     *
     * `Deployment.spec.selector` is immutable, so a Deployment whose selector
     * has changed cannot be patched — server-side apply refuses it with `field
     * is immutable`, and no amount of retrying moves it. The object has to go
     * and come back.
     *
     * Only workloads. Everything else in a compiled set updates in place, and
     * deleting a Service would take its address with it for no reason.
     *
     * @param  list<Manifest>  $manifests
     */
    private function removeWorkloads(ProjectManifest $manifest, array $manifests): void
    {
        foreach ($manifests as $compiled) {
            if ($compiled->kind === 'Deployment' || $compiled->kind === 'StatefulSet') {
                $this->kubernetes->delete(strtolower($compiled->kind), $compiled->name, $manifest->namespace());
            }
        }
    }

    /**
     * Where this environment was deployed from, kept beside it.
     *
     * In the project's own namespace, so it is deleted with everything else and
     * cannot outlive what it describes.
     *
     * @return list<ManifestDocument>
     */
    private function origin(ProjectManifest $manifest): array
    {
        if ($manifest->path === '') {
            return [];
        }

        return [ManifestDocument::fromArray([
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => self::ORIGIN,
                'namespace' => $manifest->namespace(),
                'labels' => ['platform.cbox.dk/managed' => 'true'],
            ],
            'data' => [
                'project' => $manifest->name,
                'environment' => $manifest->environment->name,
                'worktree' => $manifest->path,
            ],
        ])];
    }

    /**
     * Every project the gateway needs a certificate for, and what it serves.
     *
     * This one comes from the manifest being deployed rather than from the
     * cluster, because at this point its routes are the ones about to be
     * written: reading them back would issue a certificate for the hostnames it
     * had a moment ago.
     *
     * @return array<string, list<string>>
     */
    private function deployedProjects(ProjectManifest $manifest): array
    {
        $projects = $this->registry?->hostnames() ?? [];

        // Keyed by the DEPLOYED name, which carries the environment. Keying by
        // the project's own name makes two environments of one project the same
        // entry, and the one deployed second silently takes the first's
        // certificate away — found by raising a worktree environment beside its
        // project and seeing one certificate where there should have been two.
        $projects[$manifest->deployedName()] = $manifest->domains;

        return $projects;
    }

    /** Whether this project has ever been deployed. */
    public function namespaceExists(ProjectManifest $manifest): bool
    {
        return $this->kubernetes->read('namespace', $manifest->namespace(), '') !== null;
    }

    /**
     * @return list<ManifestDocument>
     */
    private function certificateAuthority(ProjectManifest $manifest): array
    {
        $secret = $this->kubernetes->read('secret', ClusterObjects::CA_ISSUER, 'cert-manager');

        if ($secret === null) {
            return [];
        }

        $data = is_object($secret->body->data ?? null) ? $secret->body->data : null;

        if ($data === null) {
            return [];
        }

        return [ManifestDocument::fromArray([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'kubernetes.io/tls',
            'metadata' => [
                'name' => ClusterObjects::CA_ISSUER,
                'namespace' => $manifest->namespace(),
            ],
            'data' => (array) $data,
        ])];
    }
}
