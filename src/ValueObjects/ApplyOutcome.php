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
        /**
         * What the same deploy took away. Empty from the applier itself, which
         * only writes — a sweep is a decision about a whole project's set and
         * belongs to whoever compiled it.
         */
        public Sweep $swept = new Sweep,
    ) {}

    /** The same outcome, with what the deploy removed alongside it. */
    public function including(Sweep $swept): self
    {
        return new self($this->succeeded, $this->applied, $this->output, $this->failure, $swept);
    }

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
