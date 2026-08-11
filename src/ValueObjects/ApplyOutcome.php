<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/**
 * What the cluster did with a set of manifests.
 *
 * The API server's own words are kept rather than summarised. When an apply
 * fails it is almost always a webhook, an immutable field or a missing CRD
 * saying something specific, and a tool that replaces that with "apply failed"
 * has taken away the only useful part.
 */
readonly class ApplyOutcome
{
    public function __construct(
        public bool $succeeded,
        public int $applied,
        public string $output,
        public string $failure = '',
    ) {}

    /**
     * Whether this failed because something about the object cannot be changed.
     *
     * A distinct question because it has a distinct answer. Most apply failures
     * mean "fix your manifest"; this one means "the object in the cluster
     * predates the change and has to be replaced" — which is a decision for the
     * person, not a retry.
     */
    public function blockedByImmutableField(): bool
    {
        return str_contains($this->failure, 'field is immutable');
    }
}
