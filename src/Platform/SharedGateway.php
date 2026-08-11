<?php

declare(strict_types=1);

namespace Cbox\Engine\Platform;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Project\ProjectRegistry;

/**
 * Putting the gateway back in step after a project leaves.
 *
 * THE OTHER HALF OF A DERIVED SET. A deploy recomputes the gateway because it is
 * already writing one; a removal writes nothing, so without this the gateway
 * keeps a certificate reference for a project that is gone. Envoy Gateway then
 * has a listener whose refs do not all resolve, and a listener that cannot be
 * programmed takes every other project on this machine down with it — the worst
 * possible reading of "I removed one project".
 *
 * THE ORDER MATTERS AND IS THE POINT. The gateway is rewritten FIRST, so nothing
 * references the certificate, and only then is the certificate deleted. The
 * other way round leaves a window — short, but exactly long enough for the
 * controller to notice — where the gateway names a secret that is not there.
 */
class SharedGateway
{
    public function __construct(
        private readonly Kubernetes $kubernetes,
        private readonly ProjectRegistry $registry,
        private readonly ProjectListeners $listeners = new ProjectListeners,
    ) {}

    /**
     * Drop a project from the gateway, then take its certificate away.
     *
     * The project is removed from the set explicitly rather than trusted to have
     * disappeared from the cluster already: its namespace was asked to go a
     * moment ago and deletion is not instant, so its route may well still be
     * listed.
     */
    public function forget(string $project): bool
    {
        $projects = $this->registry->hostnames();
        unset($projects[$project]);

        $applied = $this->kubernetes->apply($this->listeners->manifests($projects))->succeeded;

        if (! $applied) {
            // Leave the certificate. A gateway that still references it is
            // working; a gateway that references a secret that has been deleted
            // is not.
            return false;
        }

        $this->kubernetes->delete('certificate', $project.'-wildcard', ClusterObjects::NAMESPACE);
        $this->kubernetes->delete('secret', $project.'-wildcard-tls', ClusterObjects::NAMESPACE);

        return true;
    }
}
