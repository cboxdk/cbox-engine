<?php

declare(strict_types=1);

namespace Cbox\Engine\Tunnel;

use RuntimeException;

/**
 * One public name, and the local name it arrives at the gateway as.
 *
 * BOTH HALVES, because the tunnel does not deliver to an application — it
 * delivers to the same gateway everything else on this machine goes through, and
 * a gateway routes on the Host header. A request for `app.example.com` arriving
 * with that Host matches no route here and gets a 404 that looks like the tunnel
 * is broken when it is working perfectly.
 *
 * So the public name is rewritten to a local one on the way in, and from the
 * application's point of view the request is the same shape as one from the
 * browser on this machine: same Envoy, same headers, same TLS termination
 * decisions. That is the entire reason not to point the tunnel straight at the
 * application's Service.
 */
readonly class TunnelRoute
{
    public function __construct(
        public string $external,
        public string $local,
    ) {}

    /**
     * Read `public.example.com` or `public.example.com:local.cbox.test`.
     *
     * The short form is the common one and covers a project with a single
     * domain; the long form exists because a project with several has no
     * "obvious" local name and guessing one would route half the traffic
     * somewhere surprising.
     */
    public static function parse(string $value, string $default): self
    {
        $parts = array_map(trim(...), explode(':', trim($value)));

        if (count($parts) > 2 || $parts[0] === '') {
            throw new RuntimeException(
                "[{$value}] is not a hostname, or a public hostname and a local one separated by a colon.",
            );
        }

        $local = count($parts) === 2 ? $parts[1] : $default;

        if ($local === '') {
            throw new RuntimeException(
                "[{$parts[0]}] needs the local hostname it should arrive as: this project declares no domains, "
                .'so there is nothing to default to. Write it as public.example.com:local.cbox.test.',
            );
        }

        return new self($parts[0], $local);
    }
}
