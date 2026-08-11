<?php

declare(strict_types=1);

namespace Cbox\Engine\Testing;

use Cbox\Engine\Contracts\HttpProbe;

/**
 * A gateway that answers when a test says so, and records what was asked.
 *
 * Deny by default: a probe nothing has been told about does not answer, so a
 * test that forgets to arrange one sees the waiting behaviour rather than
 * skipping past it.
 */
class FakeHttpProbe implements HttpProbe
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $answering = false) {}

    public function answers(string $url, int $timeout = 3): bool
    {
        $this->asked[] = $url;

        return $this->answering;
    }
}
