<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/**
 * One project, as the cluster has it.
 *
 * ASLEEP AND SCALED TO ZERO ARE DIFFERENT, and telling them apart is the point
 * of this object. Both show no web pod. One was put away deliberately and its
 * database is hibernated; the other is simply idle and will answer the next
 * request in about two seconds. Reporting them the same way would make a
 * developer wake something that was never asleep.
 */
readonly class ProjectState
{
    public function __construct(
        public string $name,
        public string $namespace,
        /** Replicas the project asks for, across every process. */
        public int $wanted,
        public int $running,
        /** How many non-web processes it has. */
        public int $otherProcesses = 0,
        /** What the WEB process alone asks for, and has. */
        public int $webWanted = 0,
        public int $webRunning = 0,
    ) {}

    /** Everything is deliberately at zero — including its workers. */
    public function asleep(): bool
    {
        return $this->wanted === 0;
    }

    /**
     * Idle rather than put away: the WEB process is at zero and the rest is up.
     *
     * THE WEB PROCESS ALONE, and this was wrong until a real application was
     * deployed. "Anything running fewer replicas than it wants" reads as idle,
     * so a project whose queue worker was in CrashLoopBackOff was reported as
     * "idle, wakes on the next request" — a sentence that would cost somebody an
     * hour. Scale-to-zero puts the web process away and nothing else; a worker
     * that is down is not idleness, it is a fault.
     */
    public function idle(): bool
    {
        return ! $this->asleep() && $this->webWanted === 0 && $this->running >= $this->wanted;
    }

    /** Something that should be running is not. */
    public function degraded(): bool
    {
        return ! $this->asleep() && $this->running < $this->wanted;
    }

    /**
     * @return array{name: string, namespace: string, wanted: int, running: int, state: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'wanted' => $this->wanted,
            'running' => $this->running,
            'state' => match (true) {
                $this->asleep() => 'asleep',
                $this->idle() => 'idle',
                $this->degraded() => 'degraded',
                default => 'running',
            },
        ];
    }
}
