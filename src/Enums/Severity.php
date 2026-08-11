<?php

declare(strict_types=1);

namespace Cbox\Engine\Enums;

/**
 * How much a finding matters.
 *
 * Three levels and not two, because the middle one is the honest answer for the
 * thing this tool most needs to say: it will work, and it will not be what you
 * expected. An emulated base image is not a failure and is not fine either.
 */
enum Severity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Blocked = 'blocked';

    /** Whether this stops Cbox Local from running at all. */
    public function stopsEverything(): bool
    {
        return $this === self::Blocked;
    }
}
