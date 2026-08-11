<?php

declare(strict_types=1);

use Cbox\Engine\Support\Env;

return [

    /*
     * The addon versions this local platform runs.
     *
     * PINNED TO THE SAME VALUES AS CORTEX, and that is the whole parity claim
     * made concrete: an application meets the same Envoy Gateway, the same
     * Gateway API, and the same cert-manager on a laptop as it does on a cell.
     * A version that drifts here quietly turns "it worked locally" back into a
     * sentence nobody can act on.
     *
     * A version resolving to nothing is the dangerous case rather than the loud
     * one: `helm template` without --version silently takes whatever chart is
     * newest today. bin/render-addons.sh refuses instead.
     */
    'addons' => [
        'gateway_api' => 'v1.5.1',
        'gateway' => 'v1.8.3',
        'cert_manager' => 'v1.21.0',
        /*
         * CloudNativePG, because Postgres compiles to its `Cluster` on both
         * sides. Running a different Postgres locally would mean the one thing a
         * developer most wants to trust — that their database behaves the same —
         * was the one thing not being tested.
         */
        'cnpg_chart' => '0.29.0',
        /*
         * KEDA and its HTTP add-on, which are what scale-to-zero is made of.
         *
         * Locally this is not only a rehearsal of a production feature — it is
         * how a laptop holds fifteen projects at once. An idle project costs
         * nothing and wakes on the first request, which is the difference
         * between installing every project you work on and installing the two
         * you are working on today.
         */
        'keda_chart' => '2.20.1',
        'keda_http_chart' => '0.15.0',
    ],

    /*
     * Reaching a local environment from outside this machine.
     *
     * A tunnel is how a phone on mobile data, a webhook from a payment
     * provider, or somebody in another country calls a backend that only exists
     * on this laptop. The alternative is a port forward on the router, which
     * nobody sets up and no company's network allows.
     *
     * PINNED, like every other image here. `latest` on a component that sits in
     * the request path means two developers debugging the same problem on
     * different builds.
     */
    'tunnel' => [
        'image' => Env::string('CBOX_TUNNEL_IMAGE', 'cloudflare/cloudflared:2026.7.3'),
    ],

];
