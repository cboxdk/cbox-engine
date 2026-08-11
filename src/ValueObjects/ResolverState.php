<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/**
 * Whether this machine knows where to ask about the development domain.
 *
 * THREE STATES, and the middle one is why this is not a boolean: a resolver file
 * that exists but points somewhere else is the case that produces the strangest
 * symptom — a hostname that resolves to nothing, or to somebody else's server,
 * while every part of this product reports itself healthy.
 */
readonly class ResolverState
{
    public function __construct(
        public bool $present,
        public bool $current,
        public string $found = '',
    ) {}

    public static function missing(): self
    {
        return new self(present: false, current: false);
    }

    public static function stale(string $found): self
    {
        return new self(present: true, current: false, found: $found);
    }

    public static function installed(): self
    {
        return new self(present: true, current: true);
    }
}
