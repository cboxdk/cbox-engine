<?php

declare(strict_types=1);

namespace Cbox\Engine\Docker;

use Cbox\Engine\Contracts\HttpProbe;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The real one, over the loopback address the developer's browser uses.
 *
 * A 4xx or 5xx is an ANSWER and counts. What does not count is a connection that
 * cannot be made, which is what a gateway that is still starting looks like.
 */
class LocalHttpProbe implements HttpProbe
{
    public function answers(string $url, int $timeout = 3): bool
    {
        try {
            Http::timeout($timeout)
                ->connectTimeout($timeout)
                // Nothing here follows a redirect or reads a body: the question
                // is whether the socket answers at all.
                ->withoutRedirecting()
                ->get($url);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
