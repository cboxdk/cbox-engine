<?php

declare(strict_types=1);

namespace Cbox\Engine\Tunnel;

use Cbox\Engine\Contracts\Kubernetes;

/**
 * Which projects are currently reachable from outside this machine.
 *
 * THIS EXISTS FOR THE FORGOTTEN TUNNEL. Opening one takes a word and closing it
 * takes another, and the failure mode is not that somebody cannot open it — it
 * is that three days later a laptop is still answering the internet and nobody
 * remembers. A public address that does not appear in `cbox status` is one
 * nobody is going to close.
 */
class TunnelRegistry
{
    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * Project name => its public address, or an empty string when the address is
     * not this machine's to know.
     *
     * Empty rather than absent: a token tunnel's hostname lives in Cloudflare,
     * and "exposed, address configured elsewhere" is a different thing to report
     * than "not exposed".
     *
     * @return array<string, string>
     */
    public function running(): array
    {
        $deployments = $this->kubernetes->list('deployment', 'app.kubernetes.io/name='.CloudflareTunnel::NAME);

        $tunnels = [];

        foreach ($deployments as $deployment) {
            $project = $deployment->labels()['platform.cbox.dk/service'] ?? '';
            $namespace = $deployment->stringAt('metadata', 'namespace');

            if ($project === '' || $namespace === '') {
                continue;
            }

            $tunnels[$project] = $this->address($namespace);
        }

        return $tunnels;
    }

    /**
     * A quick tunnel's assigned address, from the connector's own log.
     *
     * Read once and not waited for. This is a status command: an address that
     * has not appeared yet will have by the next one, and a status that blocks
     * for a minute is a status nobody runs.
     */
    private function address(string $namespace): string
    {
        $found = '';

        $this->kubernetes->logs(
            $namespace,
            'app.kubernetes.io/name='.CloudflareTunnel::NAME,
            function (string $chunk) use (&$found): void {
                if ($found === '' && preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~', $chunk, $matches) === 1) {
                    $found = $matches[0];
                }
            },
            follow: false,
            tail: 200,
        );

        return $found;
    }
}
