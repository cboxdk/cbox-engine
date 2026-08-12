<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Cbox\Engine\ValueObjects\Sweep;

/**
 * The objects a project used to have and no longer asks for.
 *
 * SERVER-SIDE APPLY UPDATES; IT DOES NOT REMOVE. A compiled set is applied under
 * one field manager, and anything that was applied last time and is missing this
 * time simply stays — the API server has no idea it left the set. Every deploy
 * therefore added to the cluster and never subtracted, and the deploy said ✓
 * either way.
 *
 * WHICH IS NOT UNTIDINESS. Measured on the local cluster: a project with
 * `scale_to_zero: true` was changed to `replicas: 2` and redeployed. The apply
 * was correct — the Deployment asked for two, and two pods went ready. Nine
 * seconds later the HTTPScaledObject that nobody had removed took the count back
 * to zero and held it there, and the route no longer pointed at the interceptor
 * that could have woken it. The application was unreachable, unwakeable, and had
 * just been deployed successfully. A leftover object is not dead weight; it is a
 * second controller still acting on a workload the manifest has moved on from.
 *
 * BY AN ALLOW-LIST OF KINDS, never "everything carrying our label". Pods and
 * ReplicaSets inherit the pod template's labels, so a label-scoped sweep of
 * everything would delete the running pods of a healthy project and call it
 * housekeeping.
 *
 * AND NOTHING THAT HOLDS DATA IS EVER DELETED HERE. A StatefulSet or a Postgres
 * cluster that leaves the manifest is REPORTED and left running: dropping a line
 * from a YAML file is not consent to destroy a database, and the person who
 * meant it has `cbox remove`. The names of those are also skipped for the
 * prunable kinds, so a retained cache keeps the Service that reaches it rather
 * than being left half-dismantled.
 */
class OrphanedObjects
{
    /** Everything this tool writes, and the only thing it will consider deleting. */
    private const SELECTOR = 'platform.cbox.dk/managed=true';

    /**
     * Kinds removed when they leave the compiled set: each is rebuilt from the
     * manifest on the next deploy and holds nothing that cannot be.
     *
     * @var array<string, string>
     */
    private const PRUNABLE = [
        'Deployment' => 'apps/v1',
        'Service' => 'v1',
        'HTTPRoute' => 'gateway.networking.k8s.io/v1',
        'HTTPScaledObject' => 'http.keda.sh/v1alpha1',
        'ScaledObject' => 'keda.sh/v1alpha1',
        'HorizontalPodAutoscaler' => 'autoscaling/v2',
        'PodDisruptionBudget' => 'policy/v1',
        'Certificate' => 'cert-manager.io/v1',
    ];

    /**
     * Kinds that carry a volume. Reported when they are orphaned, never removed.
     *
     * @var array<string, string>
     */
    private const RETAINED = [
        'StatefulSet' => 'apps/v1',
        'Cluster' => 'postgresql.cnpg.io/v1',
    ];

    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * Remove what this project no longer asks for, in its own namespace.
     *
     * ONE NAMESPACE PER ENVIRONMENT is what makes this safe to scope by
     * namespace alone: `cbox-<project>` for the default one and
     * `cbox-<project>-<environment>` for the rest, so a sweep cannot reach a
     * sibling environment, let alone another project.
     *
     * @param  list<ManifestDocument>  $applied  the set about to be applied
     * @param  bool  $commit  false to work out the answer and change nothing, which
     *                        is what a dry run is for: "what would this take away"
     *                        is the question somebody most wants answered before a
     *                        deploy, and it cannot be answered from the file alone
     */
    public function sweep(string $namespace, array $applied, bool $commit = true): Sweep
    {
        $compiled = $this->identities($applied, $namespace);

        // A SET WITH NO WORKLOAD IN IT IS NOT A PROJECT. This is subtraction
        // against a desired state, so a desired state that came out empty — a
        // manifest that failed to read, a compiler that returned nothing —
        // would subtract the entire project instead. Refusing is the direction
        // whose worst case is that nothing happens.
        if (! $this->describesAProject($compiled)) {
            return new Sweep;
        }

        $retained = $this->orphanedData($namespace, $compiled);

        // The names behind the retained objects, so the Service and the route
        // that reach a kept database are kept with it.
        $keep = array_map(static fn (string $identity): string => explode('/', $identity, 2)[1], $retained);

        $removed = [];

        foreach (self::PRUNABLE as $kind => $apiVersion) {
            foreach ($this->live($kind, $apiVersion, $namespace) as $object) {
                $name = $object->name();

                if ($name === '' || isset($compiled[$kind.'/'.$name]) || in_array($name, $keep, true)) {
                    continue;
                }

                if (! $commit) {
                    $removed[] = $kind.'/'.$name;

                    continue;
                }

                if ($this->kubernetes->delete(strtolower($kind), $name, $namespace)) {
                    $removed[] = $kind.'/'.$name;
                }
            }
        }

        return new Sweep($removed, $retained);
    }

    /**
     * Whether a compiled set is something a project would actually produce.
     *
     * @param  array<string, true>  $compiled
     */
    private function describesAProject(array $compiled): bool
    {
        foreach (array_keys($compiled) as $identity) {
            if (str_starts_with($identity, 'Deployment/') || str_starts_with($identity, 'StatefulSet/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The data-bearing objects that left the manifest.
     *
     * @param  array<string, true>  $compiled
     * @return list<string>
     */
    private function orphanedData(string $namespace, array $compiled): array
    {
        $orphaned = [];

        foreach (self::RETAINED as $kind => $apiVersion) {
            foreach ($this->live($kind, $apiVersion, $namespace) as $object) {
                $name = $object->name();

                if ($name !== '' && ! isset($compiled[$kind.'/'.$name])) {
                    $orphaned[] = $kind.'/'.$name;
                }
            }
        }

        return $orphaned;
    }

    /**
     * What the cluster holds of a kind, or nothing when it does not serve it.
     *
     * KEDA, cert-manager and the Gateway API are addons: a machine that has not
     * installed one has no such kind, and asking for it is an error rather than
     * an empty list.
     *
     * @return list<ManifestDocument>
     */
    private function live(string $kind, string $apiVersion, string $namespace): array
    {
        return $this->kubernetes->serves($apiVersion, $kind)
            ? $this->kubernetes->list(strtolower($kind), self::SELECTOR, $namespace)
            : [];
    }

    /**
     * `Kind/name` for everything the compiled set puts in this namespace.
     *
     * A DOCUMENT WITHOUT A NAMESPACE COUNTS AS THIS ONE. The compiler names the
     * namespace on everything it writes, but the cost of the two mistakes is not
     * symmetric: reading a namespace-less document as belonging elsewhere would
     * mark a live object orphaned and delete it, while reading it as belonging
     * here only declines to delete something.
     *
     * @param  list<ManifestDocument>  $applied
     * @return array<string, true>
     */
    private function identities(array $applied, string $namespace): array
    {
        $identities = [];

        foreach ($applied as $document) {
            $where = $document->stringAt('metadata', 'namespace');

            if ($where === '' || $where === $namespace) {
                $identities[$document->kind().'/'.$document->name()] = true;
            }
        }

        return $identities;
    }
}
