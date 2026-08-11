<?php

declare(strict_types=1);

namespace Cbox\Engine\Platform;

use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Platform\Capability\ApplicationSource;
use Cbox\Platform\Capability\Certificates;
use Cbox\Platform\Capability\ClientPort;
use Cbox\Platform\Capability\GatewayImplementation;
use Cbox\Platform\Capability\GatewayOwnership;
use Cbox\Platform\Capability\Placement;
use Cbox\Platform\Capability\PlatformTarget;

/**
 * What this cluster can do, said once.
 *
 * EVERY DIFFERENCE FROM A CELL IS A CAPABILITY, and none of them is a branch in
 * a compiler. That is the rule the shared package is built around, and this file
 * is the whole of Cbox Local's side of it — six values, and everything else is
 * identical by construction.
 *
 * Read it as the honest list of what a laptop is not:
 *
 *   * it has one node, so there is nowhere to spread to;
 *   * no public authority can reach a name that resolves to it, so ACME cannot
 *     work at any price;
 *   * its gateway is shared by every project, because kind's port mappings are
 *     fixed at build time and two Services cannot hold the same node port;
 *   * its gateway may not be on 443, because another tool can already hold it,
 *     and the port a browser uses has to be announced to the application;
 *   * and it runs Envoy Gateway, exactly as a cell does — which is the one line
 *     here that says something is the SAME.
 */
class LocalTarget
{
    /**
     * @param  HostPorts  $ports  what the cluster is PUBLISHED on, read off the
     *                            running container — not what one would be
     *                            chosen today. A machine can hold a cluster
     *                            created back when 443 was free.
     */
    public function make(HostPorts $ports): PlatformTarget
    {
        return new PlatformTarget(
            // One node. Anti-affinity and topology spread compile to nothing,
            // and a workload that only works because it was spread across three
            // machines will look fine here — which the plan says out loud rather
            // than leaving to be discovered in production.
            placement: Placement::singleNode(),

            // The authority the cluster generated for itself. Its key pair is
            // copied into each project's namespace, which is where this
            // capability expects to find it.
            certificates: Certificates::certificateAuthority('cbox-ca'),

            // The same implementation a cell runs, so ClientTrafficPolicy — the
            // object that decides what an application believes about its client
            // — is compiled here too rather than being a production-only path.
            gateway: GatewayImplementation::envoyGateway(ClusterObjects::GATEWAY_CLASS),

            // One gateway for the machine. See ClusterObjects: the node ports it
            // publishes are pinned because kind's mappings are fixed when the
            // cluster is built, and two Services cannot hold the same ones.
            // THE ONE SUBSTRATE WHERE CODE COMES OFF A DISK. Everything else
            // here makes the local cluster behave like a cell; this is the one
            // place it deliberately does not, because a developer editing a
            // file wants the next request to run it.
            applicationSource: ApplicationSource::hostPath(ClusterConfig::HOST_PREFIX),
            gatewayOwnership: GatewayOwnership::shared(
                namespace: ClusterObjects::NAMESPACE,
                name: 'cbox',
            ),

            // WHICH PORT THE BROWSER IS ON. 18443 whenever something else holds
            // 443 — and an application cannot see that for itself, because
            // Gateway API strips the port from the authority and the pod's own
            // port is 80. Without it every login redirect on this machine goes
            // to `https://app.cbox.test/login`, which is whatever holds 443.
            clientPort: $ports->isPrivileged()
                ? ClientPort::standard()
                : ClientPort::nonStandard($ports->https),
        );
    }
}
