<?php

declare(strict_types=1);

namespace Cbox\Engine\Kind;

use Cbox\Engine\Contracts\CommandRunner;

/**
 * The ports the cluster is ACTUALLY published on.
 *
 * READ OFF THE CONTAINER, never remembered. kind fixes its port mappings when
 * the cluster is built, so a machine can easily hold a cluster created on 18443
 * back when Herd had 443, and a tool that printed what it would choose TODAY
 * would print an address that does not answer. The container knows; nothing else
 * has to.
 *
 * When there is no cluster yet the answer is what one would be given, which is
 * the right answer to "what will my address be" before `cbox up`.
 */
class PublishedPorts
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function current(): HostPorts
    {
        $result = $this->runner->run([
            'docker', 'inspect', KindCluster::NODE, '--format', '{{json .NetworkSettings.Ports}}',
        ], timeout: 15);

        if (! $result->successful()) {
            return HostPorts::preferred();
        }

        /** @var mixed $decoded */
        $decoded = json_decode($result->text(), true);

        if (! is_array($decoded)) {
            return HostPorts::preferred();
        }

        $https = $this->hostPort($decoded, ClusterConfig::HTTPS_NODE_PORT.'/tcp');

        // The HTTPS port decides. All three are chosen together, and a cluster
        // that somehow published a mixture is one where the address a developer
        // types is the thing to get right.
        if ($https === HostPorts::PRIVILEGED_HTTPS) {
            return HostPorts::privileged();
        }

        return HostPorts::high();
    }

    /**
     * @param  array<mixed>  $ports
     */
    private function hostPort(array $ports, string $key): int
    {
        $bindings = $ports[$key] ?? null;

        if (! is_array($bindings)) {
            return 0;
        }

        foreach ($bindings as $binding) {
            $port = is_array($binding) ? ($binding['HostPort'] ?? null) : null;

            if (is_string($port) && $port !== '') {
                return (int) $port;
            }
        }

        return 0;
    }
}
