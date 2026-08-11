<?php

declare(strict_types=1);

namespace Cbox\Engine\Tunnel;

/**
 * How a tunnel is authorised, which is the only thing that really differs
 * between them.
 *
 * The three exist because a developer needs different things on different days,
 * and a product that only shipped one of them would be wrong for most of the
 * week:
 */
enum TunnelMode: string
{
    /**
     * A throwaway address on `trycloudflare.com`, no account needed.
     *
     * The one somebody uses at 4pm because a webhook has to reach their laptop
     * before the end of the day. It has no stable name and Cloudflare may
     * rate-limit it, and it is still the right default: nothing to sign up for,
     * nothing to configure, working in about ten seconds.
     */
    case Quick = 'quick';

    /**
     * A named tunnel from the dashboard, addressed by its token.
     *
     * The tunnel's routing lives in Cloudflare rather than here — this runs it
     * and nothing more. The hostname and the origin are set once in the
     * dashboard, and every machine that runs the token gets the same one.
     */
    case Token = 'token';

    /**
     * A named tunnel this machine holds the credentials for.
     *
     * The only mode where the ingress is OURS, so it is the only one that can
     * put several hostnames through one tunnel and set the Host header each of
     * them arrives at the gateway with. For a real domain on a real project,
     * this is the one.
     */
    case Credentials = 'credentials';
}
