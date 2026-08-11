<?php

declare(strict_types=1);

namespace Cbox\Engine\Platform;

use Cbox\Engine\Contracts\Kubernetes;

/**
 * Where the gateway can be reached from inside the cluster.
 *
 * ASKED FOR, NOT ASSEMBLED. Envoy Gateway names the Service it creates after the
 * gateway plus a hash of something it owns —
 * `envoy-cbox-system-cbox-445c9218` — and that hash is not ours to predict. A
 * tool that built the name would work until the day the hash changed, and then
 * fail with a DNS error that reads like a broken cluster.
 *
 * So it is found by the label Envoy Gateway puts on it, which is the part it
 * documents.
 */
class GatewayAddress
{
    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * The gateway's in-cluster hostname, or null if it is not up.
     *
     * Without a port. Which one a caller wants depends on what it is doing, and
     * a method that answered with one baked in would have to be read twice by
     * anybody who wanted the other.
     */
    public function internal(): ?string
    {
        $services = $this->kubernetes->list('service', 'gateway.envoyproxy.io/owning-gateway-name='.ClusterObjects::GATEWAY);

        foreach ($services as $service) {
            $name = $service->name();
            $namespace = $service->stringAt('metadata', 'namespace');

            if ($name === '' || $namespace === '') {
                continue;
            }

            return $name.'.'.$namespace.'.svc.cluster.local';
        }

        return null;
    }
}
