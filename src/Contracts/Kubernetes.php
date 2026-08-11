<?php

declare(strict_types=1);

namespace Cbox\Engine\Contracts;

use Cbox\Engine\ValueObjects\ApplyOutcome;
use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * Writing desired state into the local cluster.
 *
 * WHY THIS IS NOT THE BRIDGE, having argued earlier that it should be. Cortex
 * reaches clusters only through a Go service, and that service exists for
 * reasons that do not hold here: a multi-tenant SaaS must not hold cluster
 * credentials in a web application, and one tenant's compiled objects must be
 * provably unable to reach another's. Locally there is one cluster, it belongs
 * to the person at the keyboard, and they already have root on it. Deploying a
 * service, a token and a TLS certificate to enforce a boundary that does not
 * exist would be complexity bought with nothing.
 *
 * What was worth taking from the bridge is its SEMANTICS, and those are kept:
 * server-side apply under one field manager, a dry run that goes through the
 * real admission chain, and refusing rather than guessing. The manifests are
 * identical either way — they come from the same compiler — so the contract a
 * developer's application meets is the same. The applier is substrate.
 */
interface Kubernetes
{
    /**
     * @param  list<ManifestDocument>  $manifests
     * @param  bool  $dryRun  send everything through the API server's admission
     *                        chain — schema, defaulting, webhooks — and persist
     *                        nothing. The only way to know a change will apply.
     */
    public function apply(array $manifests, bool $dryRun = false): ApplyOutcome;

    /**
     * Remove one object. Absent is success: a delete is how a caller reaches a
     * state, and one that fails because it already holds is a delete that
     * cannot be retried.
     */
    public function delete(string $kind, string $name, string $namespace): bool;

    /**
     * One object as the cluster holds it, or null when it is not there.
     *
     * Null rather than an exception: "not there yet" is an ordinary answer to
     * this question and the caller usually has something sensible to do with it.
     */
    public function read(string $kind, string $name, string $namespace): ?ManifestDocument;

    /**
     * Hand a workload's logs over as they arrive.
     *
     * @param  callable(string): void  $onOutput
     * @param  bool  $follow  keep the stream open. Unbounded on purpose: the
     *                        person who started it ends it.
     */
    public function logs(
        string $namespace,
        string $selector,
        callable $onOutput,
        bool $follow = false,
        int $tail = 100,
    ): bool;

    /**
     * Every object of a kind carrying a label, across the cluster.
     *
     * @return list<ManifestDocument>
     */
    public function list(string $kind, string $selector, string $namespace = ''): array;

    /** Whether a kind is served by the cluster yet. CRDs take a moment. */
    public function serves(string $apiVersion, string $kind): bool;

    /**
     * Do not return until every workload in these namespaces is available.
     *
     * An addon is not installed when its objects are stored; it is installed
     * when its controllers and its WEBHOOKS are answering. Anything applied in
     * between is refused by an admission webhook whose pod does not exist yet,
     * and the error names a connection refused rather than a race.
     *
     * @param  list<string>  $namespaces
     */
    /**
     * Run a command inside a running pod, with this terminal attached.
     *
     * THE ESCAPE HATCH THAT IS NOT AN ESCAPE. `artisan migrate`, `composer
     * install`, `tinker`, `psql` — a developer platform that cannot run a
     * command inside the thing it is running is one people keep `kubectl`
     * beside, and then the platform is a wrapper rather than a tool.
     *
     * WITHOUT A TERMINAL IT STILL HAS TO SPEAK. A caller that cannot attach one
     * — a script, CI, an agent — gets the output through the callback instead,
     * because a command that ran and said nothing is indistinguishable from one
     * that did not run.
     *
     * @param  list<string>  $command
     * @param  (callable(string): void)|null  $onOutput  ignored when a terminal is attached: the
     *                                                   program is already writing to it
     * @return int the command's own exit code, or -1 when it could not be run
     */
    public function exec(
        string $namespace,
        string $selector,
        array $command,
        bool $tty = true,
        ?callable $onOutput = null,
    ): int;

    /**
     * @param  list<string>  $namespaces
     */
    public function waitForWorkloads(array $namespaces, int $seconds = 180): bool;
}
