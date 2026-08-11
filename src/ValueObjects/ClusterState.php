<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

use Cbox\Engine\Enums\ClusterPhase;

/**
 * What the local cluster is, and what just happened to it.
 *
 * `changed` says whether this call DID something. `cbox up` on a running cluster
 * and `cbox up` that built one both end with a cluster running, and a developer
 * waiting on the second needs to be told the difference — silence after a
 * three-minute build reads as a hang.
 */
readonly class ClusterState
{
    public function __construct(
        public string $name,
        public ClusterPhase $phase,
        public bool $changed = false,
        /** The kubectl context, when there is one. */
        public string $context = '',
        public string $failure = '',
    ) {}

    public function running(): bool
    {
        return $this->phase === ClusterPhase::Running;
    }

    /**
     * @return array{name: string, phase: string, changed: bool, context: string, failure: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phase' => $this->phase->value,
            'changed' => $this->changed,
            'context' => $this->context,
            'failure' => $this->failure,
        ];
    }
}
