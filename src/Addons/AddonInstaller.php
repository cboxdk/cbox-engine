<?php

declare(strict_types=1);

namespace Cbox\Engine\Addons;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Platform\LocalAuthority;
use Cbox\Engine\Platform\ProjectListeners;
use Cbox\Engine\ValueObjects\AddonResult;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Throwable;

/**
 * Puts the addons into the cluster, one at a time and in order.
 *
 * ONE AT A TIME because they depend on each other, and a single apply of
 * everything would be one ordering nobody chose. STOPS at the first failure for
 * the same reason: applying cert-manager into a cluster whose Gateway API kinds
 * never appeared produces a second, more confusing error on top of the first.
 *
 * Idempotent, because server-side apply is. Running this on a converged cluster
 * changes nothing and says so.
 */
class AddonInstaller
{
    public function __construct(
        private readonly AddonSet $addons,
        private readonly Kubernetes $kubernetes,
        private readonly ClusterObjects $cluster,
        private readonly ProjectListeners $listeners = new ProjectListeners,
        /**
         * The authority this machine trusts, kept outside the cluster.
         *
         * Optional so a test can leave it out; when it is here, the root is
         * saved the first time cert-manager mints it.
         */
        private readonly ?LocalAuthority $authority = null,
    ) {}

    /**
     * @param  list<ManifestDocument>  $manifests
     * @return list<string>
     */
    private function namespaceNames(array $manifests): array
    {
        return array_map(
            static fn (ManifestDocument $m): string => $m->name(),
            $this->namespacesFor($manifests),
        );
    }

    /**
     * The namespaces an addon's objects live in, as objects of their own.
     *
     * `helm template` does NOT emit them. `--namespace` tells the renderer where
     * to address things, and creating it is `helm install`'s job — which is not
     * running here. So a rendered chart applied verbatim fails on its first
     * namespaced object:
     *
     *     Error from server (NotFound): namespaces "envoy-gateway-system" not found
     *
     * DERIVED from the manifests rather than kept as a list. A list is a second
     * place to update when a chart moves something, and the failure when it
     * drifts is this same error at install time.
     *
     * Nothing but the name is set, so applying one that already exists claims
     * nothing that was not already ours.
     *
     * @param  list<ManifestDocument>  $manifests
     * @return list<ManifestDocument>
     */
    private function namespacesFor(array $manifests): array
    {
        $names = [];

        foreach ($manifests as $manifest) {
            $metadata = $manifest->body->metadata ?? null;
            $namespace = is_object($metadata) ? ($metadata->namespace ?? null) : null;

            if (is_string($namespace) && $namespace !== '' && ! in_array($namespace, $names, true)) {
                $names[] = $namespace;
            }
        }

        return array_map(static fn (string $name): ManifestDocument => ManifestDocument::fromArray([
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => ['name' => $name],
        ]), $names);
    }

    /**
     * @return list<AddonResult>
     */
    public function install(bool $dryRun = false): array
    {
        $results = [];

        foreach ($this->addons->names() as $name) {
            try {
                $manifests = $this->addons->manifests($name);
            } catch (Throwable $e) {
                $results[] = new AddonResult($name, succeeded: false, objects: 0, failure: $e->getMessage());

                return $results;
            }

            $outcome = $this->kubernetes->apply(
                array_merge($this->namespacesFor($manifests), $manifests),
                $dryRun,
            );

            $results[] = new AddonResult(
                $name,
                succeeded: $outcome->succeeded,
                objects: $outcome->applied,
                failure: $outcome->failure,
            );

            if (! $outcome->succeeded) {
                return $results;
            }

            // AN ADDON IS NOT INSTALLED WHEN ITS OBJECTS ARE STORED. It is
            // installed when its controllers and webhooks are answering.
            // Measured on a fresh cluster: the cluster objects below reached
            // cert-manager's validating webhook seconds after its Deployment
            // was created, and the API server refused all four with
            //
            //   failed calling webhook "webhook.cert-manager.io": ...
            //   connect: connection refused
            //
            // A dry run has no pods to wait for, and waiting would hang.
            if (! $dryRun) {
                $this->kubernetes->waitForWorkloads($this->namespaceNames($manifests));
            }
        }

        // LAST, because every one of them names a kind an addon defines: the
        // GatewayClass needs the Gateway API, the EnvoyProxy needs Envoy
        // Gateway's own CRDs, and the issuers need cert-manager's. Applied
        // first they would fail with `no matches for kind`.
        // The gateway with no project listeners yet — a cluster that has just
        // been built has no projects, and one that has them gets them back on
        // the next deploy. Installed here so a fresh cluster serves before
        // anybody deploys anything.
        $outcome = $this->kubernetes->apply(
            [...$this->cluster->manifests(), ...$this->listeners->manifests([])],
            $dryRun,
        );

        $results[] = new AddonResult(
            'cluster-objects',
            succeeded: $outcome->succeeded,
            objects: $outcome->applied,
            failure: $outcome->failure,
        );

        // KEPT, THE FIRST TIME IT EXISTS. cert-manager has just minted the
        // authority every local certificate is signed by; saving it outside the
        // cluster is what lets somebody trust it once instead of once per
        // `cbox destroy`. It is captured only when nothing is saved, so an
        // ordinary converge never replaces a root this machine already trusts.
        if ($outcome->succeeded && ! $dryRun) {
            $this->authority?->capture();
        }

        return $results;
    }
}
