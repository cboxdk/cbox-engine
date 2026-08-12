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
        /** Whether a scaler is watching for a request to raise this from zero. */
        public bool $wakesOnRequest = false,
    ) {}

    /**
     * Everything is deliberately at zero, and nothing is watching for a request.
     *
     * THE SECOND HALF IS THE WHOLE POINT, and leaving it out made this wrong for
     * the commonest project there is. A scale-to-zero application with no worker
     * has nothing left running once its web process idles down, so `wanted === 0`
     * matched and status said "asleep — `cbox wake` brings it back" about a
     * project that wakes itself on the next request. The distinction only
     * survived for a project that happened to own a second process.
     *
     * Measured on the local cluster with a one-process project: deployed at
     * zero, woken by a request in 6s, idled back down — and reported as put
     * away, with an instruction nobody needed to follow.
     */
    public function asleep(): bool
    {
        return $this->wanted === 0 && ! $this->wakesOnRequest;
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
