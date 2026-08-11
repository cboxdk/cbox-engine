<?php

declare(strict_types=1);

namespace Cbox\Engine\Tunnel;

use RuntimeException;

/**
 * What a project is exposed as.
 *
 * Assembled and CHECKED here rather than in the command, so the impossible
 * combinations are impossible everywhere: a token mode with no token, a
 * credentials mode with no hostnames to route to. A command that validated its
 * own options would leave every other caller — the desktop application, a test,
 * whatever comes next — to remember the same rules.
 */
readonly class TunnelSpec
{
    /**
     * @param  list<TunnelRoute>  $routes  public names and what they arrive as
     * @param  string  $secret  the tunnel token, or the credentials JSON
     */
    public function __construct(
        public TunnelMode $mode,
        public array $routes = [],
        public string $secret = '',
    ) {
        if ($mode !== TunnelMode::Quick && $secret === '') {
            throw new RuntimeException("A {$mode->value} tunnel cannot run without its credentials.");
        }

        if ($mode === TunnelMode::Quick && count($routes) !== 1) {
            throw new RuntimeException(
                'A quick tunnel is one address that Cloudflare picks, so it carries exactly one local hostname.',
            );
        }

        if ($mode === TunnelMode::Credentials && $routes === []) {
            throw new RuntimeException(
                'A credentials tunnel routes hostnames this machine decides, so at least one has to be named.',
            );
        }
    }

    /**
     * The tunnel's own id, read out of the credentials it was given.
     *
     * FROM THE FILE, not asked for separately. The id is already in there, and a
     * tool that asks for it again is a tool that can be told one that does not
     * match the credentials — which fails at connection time with an error about
     * authentication that sends somebody looking in the wrong place.
     */
    public function tunnelId(): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($this->secret, true);

        $id = is_array($decoded) ? ($decoded['TunnelID'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException(
                'That does not look like a cloudflared credentials file: it has no TunnelID. '
                .'It is the JSON written by `cloudflared tunnel create`, usually under ~/.cloudflared.',
            );
        }

        return $id;
    }
}
