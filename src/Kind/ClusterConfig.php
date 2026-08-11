<?php

declare(strict_types=1);

namespace Cbox\Engine\Kind;

use Cbox\Engine\Support\Env;
use RuntimeException;

/**
 * The cluster's shape, written where kind can read it.
 *
 * FIXED AT CREATION AND NOT AFTER. kind reads this once; changing it later means
 * destroying the cluster and everything in it. So the things that are hard to
 * add later are here from the first run, even before anything uses them.
 *
 * THE REAL PORTS WHEN THEY ARE FREE. The gateway inside the cluster listens on
 * 80 and 443 as it does in production, and those are published on 80 and 443 on
 * the host unless something already holds them — see {@see HostPorts}, which
 * decides and explains why no privilege is involved. When Herd or anything else
 * has them, this takes 18080 and 18443 instead: coexisting rather than demanding
 * to be the only thing installed.
 *
 * `ingress-ready` is the label every Gateway and ingress chart looks for to
 * decide a node may accept outside traffic. Absent, the gateway schedules and
 * then answers nothing, which looks exactly like a broken gateway.
 */
class ClusterConfig
{
    /**
     * The node ports the gateway's service is pinned to.
     *
     * Pinned rather than allocated, because kind's port mappings are fixed when
     * the cluster is BUILT and a randomly allocated NodePort would land
     * somewhere the host cannot reach. The consequence is that exactly one
     * gateway service may hold them — which is why there is one shared gateway
     * for the whole cluster rather than one per project, exactly as there is one
     * cluster rather than one per project.
     */
    public const HTTP_NODE_PORT = 30080;

    public const HTTPS_NODE_PORT = 30443;

    /**
     * Where the cluster answers DNS.
     *
     * On the host it is port 53 when that is free, and an unprivileged port when
     * it is not — macOS lets `/etc/resolver/<domain>` name one with a `port`
     * directive either way. The one privileged act in this whole product is
     * writing that five-line file, once, rather than an `/etc/hosts` entry per
     * project and a password prompt on every deploy.
     */
    public const DNS_NODE_PORT = 30053;

    /**
     * Where the developer's home directory appears inside the node.
     *
     * A PREFIX RATHER THAN A LIST OF PROJECTS, because the mounts are fixed when
     * the cluster is built and a per-project mount would mean rebuilding the
     * cluster — destroying every database on it — to start work on something
     * new. One mount, and a project anywhere under it just works.
     *
     * The home directory rather than `/`: this is the developer's own machine
     * and their code is in it, and mounting the whole filesystem into a
     * container would hand anything running there the rest of the disk.
     */
    public const HOST_PREFIX = '/host';

    /**
     * The addresses a pod can have on this cluster.
     *
     * kind's default, and it is not cosmetic: the base image's nginx uses it to
     * decide which client an `X-Forwarded-For` may speak for. Without it an
     * application sees the GATEWAY's address as its client on every request —
     * the exact class of bug this product exists to surface, and it would have
     * been producing it rather than exposing it.
     */
    public const POD_CIDR = '10.244.0.0/16';

    public function __construct(
        private readonly string $path,
        private readonly HostPorts $ports = new HostPorts(
            HostPorts::HIGH_HTTP,
            HostPorts::HIGH_HTTPS,
            HostPorts::HIGH_DNS,
        ),
    ) {}

    /** Write it and return the path kind should read. */
    public function write(): string
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create [{$directory}] to write the cluster config into.");
        }

        if (file_put_contents($this->path, $this->render()) === false) {
            throw new RuntimeException("Could not write the cluster config to [{$this->path}].");
        }

        return $this->path;
    }

    public function render(): string
    {
        $http = $this->ports->http;
        $https = $this->ports->https;
        $httpNode = self::HTTP_NODE_PORT;
        $httpsNode = self::HTTPS_NODE_PORT;
        $dns = $this->ports->dns;
        $dnsNode = self::DNS_NODE_PORT;
        $home = $this->home();
        $prefix = self::HOST_PREFIX;

        return <<<YAML
        # Written by Cbox Local. Edit the generator, not this file: it is
        # rewritten every time the cluster is created.
        kind: Cluster
        apiVersion: kind.x-k8s.io/v1alpha4
        name: {$this->name()}
        nodes:
          - role: control-plane
            kubeadmConfigPatches:
              # Without this label the gateway schedules and then answers
              # nothing, which is indistinguishable from a broken gateway.
              - |
                kind: InitConfiguration
                nodeRegistration:
                  kubeletExtraArgs:
                    node-labels: "ingress-ready=true"
            extraPortMappings:
              # To the gateway's pinned node ports, not to 80 and 443 on the
              # node — nothing listens there. A NodePort service is what a kind
              # cluster can publish: there is no cloud load balancer to allocate,
              # and a LoadBalancer service would sit Pending forever.
              - containerPort: {$httpNode}
                hostPort: {$http}
                protocol: TCP
              - containerPort: {$httpsNode}
                hostPort: {$https}
                protocol: TCP
              # DNS, so a project's hostname resolves in a browser rather than
              # only under `curl --resolve`. UDP, which is what a resolver asks
              # over, and TCP beside it for answers that do not fit.
              - containerPort: {$dnsNode}
                hostPort: {$dns}
                protocol: UDP
              - containerPort: {$dnsNode}
                hostPort: {$dns}
                protocol: TCP
            extraMounts:
              # The developer's home directory, so a project can run from the
              # working copy rather than from an image built out of it. Fixed
              # here for the same reason the ports are: kind reads this once,
              # and a mount added later means destroying the cluster.
              - hostPath: {$home}
                containerPath: {$prefix}{$home}

        YAML;
    }

    private function name(): string
    {
        return KindCluster::NAME;
    }

    /**
     * The directory the developer's projects live under.
     *
     * Their home, and it has to be somewhere kind can mount: an empty HOME would
     * mount `/` into the node, which is the one outcome worth refusing outright.
     */
    private function home(): string
    {
        $home = Env::string('HOME', '');

        if ($home === '' || $home === '/') {
            throw new RuntimeException(
                'This machine has no home directory set, so there is nowhere to mount for running code '
                .'from a working copy. Set HOME and try again.',
            );
        }

        return rtrim($home, '/');
    }
}
