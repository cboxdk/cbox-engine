<?php

declare(strict_types=1);

namespace Cbox\Engine\Contracts;

/**
 * Asking the gateway, over the same path a developer's browser takes.
 *
 * THE SECOND THING THAT TOUCHES THE HOST, after `CommandRunner`, and it is here
 * as its own contract rather than folded into that one because it is a different
 * question: not "did a program run" but "does the address a person will type
 * answer yet".
 *
 * That distinction is the point. Every structural signal a cluster offers said
 * the gateway was ready — Deployment Available, Gateway Programmed, pods 2/2 —
 * while requests to it failed to connect for well over a minute after a restart.
 * The only honest answer to "can I open my site" is to open it.
 */
interface HttpProbe
{
    /**
     * Whether ANYTHING answered — a status, any status.
     *
     * A 404 is a healthy gateway with no route for that hostname, which is
     * exactly what an empty platform should say. Waiting for a 200 would mean
     * waiting for somebody to deploy something.
     */
    public function answers(string $url, int $timeout = 3): bool;
}
