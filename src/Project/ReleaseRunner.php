<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;
use Closure;

/**
 * A project's release command, run to completion before anything rolls.
 *
 * `php artisan migrate --force` is the case this exists for. It has to run
 * against the NEW release's code — the old pods carry the old migrations — and
 * it has to finish before the new pods start serving, because they expect the
 * schema it creates. The previous release keeps serving throughout: its
 * Deployments are not touched until this has said yes.
 *
 * IT ANSWERS WITH A SENTENCE OR NOTHING. Null is "done, carry on"; anything
 * else is why the deploy stopped, in words the person reading it can act on —
 * the release's own output when it failed, the reason its pod could not start
 * when it never ran.
 *
 * NOTHING WAITS LONGER THAN IT HAS TO. A pod that cannot start — a `secrets`
 * entry naming a Secret that does not exist, an image that cannot be pulled —
 * never finishes on its own, and the Job's own deadline is an hour. Such a pod
 * is reported as soon as the reason has held for a few polls, which separates a
 * Secret that is genuinely missing from one that is a second from existing.
 */
class ReleaseRunner
{
    /** Consecutive polls a pod must stay stuck before that is the answer. */
    private const STUCK_POLLS = 5;

    /** @var Closure(int): void */
    private Closure $pause;

    /**
     * @param  int  $polls  how many times to look before giving up — at the
     *                      default interval, a little over the Job's own hour
     * @param  (Closure(int): void)|null  $pause  how to wait between looks;
     *                                            replaced in tests so they do not sleep
     */
    public function __construct(
        private readonly Kubernetes $kubernetes,
        private readonly ProjectHealth $health,
        private readonly int $interval = 2,
        private readonly int $polls = 1850,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Wait for the databases, run the release, and say whether it worked.
     *
     * @param  list<ManifestDocument>  $databases  the compiled database objects
     *                                             the release will connect to
     * @param  list<string>  $command  what the Job runs, for saying so
     */
    public function run(ManifestDocument $job, string $namespace, array $databases, array $command = []): ?string
    {
        $waiting = $this->awaitDatabases($databases, $namespace);

        if ($waiting !== null) {
            return $waiting;
        }

        $name = $job->name();
        $command = $command === [] ? $name : implode(' ', $command);
        $existing = $this->kubernetes->read('job', $name, $namespace);

        // THE SAME RELEASE, ALREADY RUN. The Job is named for its pod template,
        // so a redeploy of the same thing finds it complete and does not
        // migrate a second time.
        if ($existing !== null && $existing->condition('Complete') === 'True') {
            return null;
        }

        // THE SAME RELEASE, FAILED LAST TIME — and being deployed again because
        // somebody fixed what made it fail. A finished Job never runs again, so
        // without this the retry would report the old failure without trying.
        if ($existing !== null && $existing->condition('Failed') === 'True') {
            $this->kubernetes->delete('job', $name, $namespace);
        }

        $applied = $this->kubernetes->apply([$job]);

        if (! $applied->succeeded) {
            return "The release `{$command}` could not be started: {$applied->failure}";
        }

        $stuck = null;
        $times = 0;

        for ($poll = 0; $poll < $this->polls; $poll++) {
            $current = $this->kubernetes->read('job', $name, $namespace);

            if ($current !== null && $current->condition('Complete') === 'True') {
                return null;
            }

            if ($current !== null && $current->condition('Failed') === 'True') {
                return $this->failed($name, $namespace, $command);
            }

            $blocked = $this->health->blockedPod($namespace, 'job-name='.$name);
            $times = $blocked !== null && $blocked === $stuck ? $times + 1 : ($blocked !== null ? 1 : 0);
            $stuck = $blocked;

            if ($blocked !== null && $times >= self::STUCK_POLLS) {
                // Taken away, so the next deploy starts it afresh rather than
                // finding a Job still waiting on the Secret it was given an
                // hour to find.
                $this->kubernetes->delete('job', $name, $namespace);

                return "The release `{$command}` never started, so nothing was rolled out and the "
                    .'running release is untouched. '.$blocked;
            }

            ($this->pause)($this->interval);
        }

        return "The release `{$command}` did not finish in time, so nothing was rolled out and the "
            ."running release is untouched. `kubectl logs -n {$namespace} job/{$name}` has what it said.";
    }

    /**
     * Every database the release will connect to, accepting connections.
     *
     * ON A FIRST DEPLOY THE DATABASE IS SECONDS OLD. CloudNativePG initialises
     * the cluster and only then writes the Secret the binding reads; a
     * migration started before that either waits on a Secret that does not
     * exist yet or connects to nothing — and a release Job is never retried, so
     * either one would fail the very first deploy of every project with a
     * database.
     *
     * @param  list<ManifestDocument>  $databases
     */
    private function awaitDatabases(array $databases, string $namespace): ?string
    {
        foreach ($databases as $database) {
            $kind = $database->kind();

            if ($kind !== 'Cluster' && $kind !== 'StatefulSet') {
                continue;
            }

            for ($poll = 0; ; $poll++) {
                $live = $this->kubernetes->read(strtolower($kind), $database->name(), $namespace);

                if ($live !== null && $this->accepting($live)) {
                    break;
                }

                if ($poll >= intdiv(300, max(1, $this->interval))) {
                    return "The database [{$database->name()}] did not become ready in five minutes, so the "
                        .'release was not run and nothing was rolled out. `cbox status` says more.';
                }

                ($this->pause)($this->interval);
            }
        }

        return null;
    }

    private function accepting(ManifestDocument $database): bool
    {
        return $database->kind() === 'Cluster'
            ? $database->intAt('status', 'readyInstances') >= max(1, $database->intAt('spec', 'instances'))
            : $database->intAt('status', 'readyReplicas') >= max(1, $database->intAt('spec', 'replicas'));
    }

    /** Why it failed, in its own words: the last of what it printed. */
    private function failed(string $name, string $namespace, string $command): string
    {
        $output = '';

        $this->kubernetes->logs($namespace, 'job-name='.$name, function (string $chunk) use (&$output): void {
            $output .= $chunk;
        }, tail: 30);

        $output = trim($output);

        return "The release `{$command}` failed, so nothing was rolled out and the running release is "
            .'untouched.'.($output !== '' ? "\n\n".$output : " `kubectl logs -n {$namespace} job/{$name}` has what it said.");
    }
}
