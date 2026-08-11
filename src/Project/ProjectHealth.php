<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * Whether what was just deployed is actually running.
 *
 * A DEPLOY THAT REPORTS SUCCESS AND SERVES NOTHING is the worst answer this tool
 * can give, and it is the easy one to write: the objects applied, the API server
 * accepted them, and the command is finished. Meanwhile the pod cannot pull its
 * image and sits in `ImagePullBackOff` where nobody but `kubectl describe` will
 * ever see the reason.
 *
 * Found exactly that way — a deploy said `✓ livedemo deployed — 8 objects` for
 * an image with no build for this machine's architecture.
 */
class ProjectHealth
{
    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * Wait for the project's pods, and say what is wrong if they do not come up.
     *
     * Returns null when everything is running. A SENTENCE, not a status: the
     * person reading it wants to know what to do, and `ImagePullBackOff` is a
     * word that only helps somebody who already knows what it means.
     */
    public function awaitReady(string $namespace, int $seconds = 90): ?string
    {
        $deadline = time() + max(5, $seconds);

        // How long a workload has to APPEAR before this concludes there is not
        // one. Separate from the deadline, and much shorter: an object that was
        // just applied is in the API server immediately, so a namespace still
        // empty after this has nothing coming — and a manifest of nothing but
        // databases is an ordinary thing to deploy.
        $appears = time() + 10;
        $previous = null;

        while (time() < $deadline) {
            $deployments = $this->kubernetes->list('deployment', 'platform.cbox.dk/managed=true', $namespace);

            if ($deployments === [] && time() > $appears) {
                return null;
            }

            // NOTHING TO WAIT FOR is a real answer and not a failure. A project
            // that is asleep, or scaled to zero, has every workload at zero
            // replicas on purpose — waiting for a pod there would hang for the
            // whole deadline and then report that a correct deploy did not
            // work. Also the honest answer for a manifest of nothing but
            // databases.
            if ($deployments !== [] && $this->satisfied($deployments)) {
                return null;
            }

            $blocked = $this->blockedIn($namespace);

            // A pull that is failing has already failed: waiting out the rest
            // of the deadline adds a minute and a half of silence to an answer
            // this already has.
            //
            // TWICE, THOUGH. The pod of a PREVIOUS rollout is still listed
            // while it terminates, and it carries the reason the deploy that
            // is being replaced failed for. Measured: fixing a broken image and
            // deploying again reported the old pod's failure while the new pod
            // was starting perfectly well beside it. A real failure is still
            // there two seconds later; a pod on its way out is not.
            if ($blocked !== null && $blocked === $previous) {
                return $blocked;
            }

            $previous = $blocked;

            sleep(2);
        }

        return $this->blockedIn($namespace)
            ?? 'The workloads did not start in time. `cbox status` and `cbox logs` say more.';
    }

    /**
     * Whether every workload has finished rolling out.
     *
     * READY IS NOT ENOUGH, and this is the whole subtlety. A Deployment whose
     * image was just changed still reports its OLD pods ready — the rollout has
     * not begun — so a check on `readyReplicas` alone passes instantly and
     * reports success for a deploy that is about to fail. Measured: a project
     * pointed at an image with no build for this architecture deployed
     * "successfully" because the previous, working pod was still up.
     *
     * So: the controller must have seen this version of the object
     * (`observedGeneration`), every replica must be the NEW one
     * (`updatedReplicas`), and they must be ready. That is what `kubectl rollout
     * status` waits for, and for the same reasons.
     *
     * @param  list<ManifestDocument>  $deployments
     */
    private function satisfied(array $deployments): bool
    {
        foreach ($deployments as $deployment) {
            $wanted = $deployment->intAt('spec', 'replicas');

            $updated = $deployment->intAt('status', 'updatedReplicas');

            $seen = $deployment->intAt('status', 'observedGeneration') >= $deployment->intAt('metadata', 'generation');
            $rolled = $updated >= $wanted;
            // NO OLD PODS LEFT. Measured, and it is why the two checks above
            // were not enough: during a rolling update `readyReplicas` counts
            // the PREVIOUS pod, which is ready and about to be deleted, so a
            // deploy of an image that cannot even be pulled reported success
            // one second after applying. `status.replicas` counts every pod the
            // Deployment owns; while it exceeds the updated count, the rollout
            // is still happening.
            $replaced = $deployment->intAt('status', 'replicas') <= $updated;
            $ready = $deployment->intAt('status', 'readyReplicas') >= $wanted;

            if (! $seen || ! $rolled || ! $replaced || ! $ready) {
                return false;
            }
        }

        return true;
    }

    /** The first pod in this namespace that is stuck for a reason worth saying. */
    private function blockedIn(string $namespace): ?string
    {
        foreach ($this->kubernetes->list('pod', 'platform.cbox.dk/managed=true', $namespace) as $pod) {
            // A pod that has been asked to go is not evidence of anything.
            if ($pod->stringAt('metadata', 'deletionTimestamp') !== '') {
                continue;
            }

            $blocked = $this->blocked($pod);

            if ($blocked !== null) {
                return $blocked;
            }
        }

        return null;
    }

    /**
     * The reason a pod is stuck, when it is stuck for a reason worth reporting.
     *
     * Only the terminal ones. `ContainerCreating` and `PodInitializing` are how
     * every healthy pod starts, and reporting them would turn a slow first pull
     * into a failure.
     */
    private function blocked(ManifestDocument $pod): ?string
    {
        $status = $pod->body->status ?? null;
        $statuses = is_object($status) ? ($status->containerStatuses ?? null) : null;

        if (! is_array($statuses)) {
            return null;
        }

        foreach ($statuses as $container) {
            $state = is_object($container) ? ($container->state ?? null) : null;
            $waiting = is_object($state) ? ($state->waiting ?? null) : null;

            if (! is_object($waiting)) {
                continue;
            }

            $reason = is_string($waiting->reason ?? null) ? $waiting->reason : '';
            $message = is_string($waiting->message ?? null) ? $waiting->message : '';

            $sentence = $this->explain($reason, $message);

            if ($sentence !== null) {
                return $sentence;
            }
        }

        return null;
    }

    private function explain(string $reason, string $message): ?string
    {
        // THE ONE THIS MACHINE MEETS DAILY, and the error the API gives for it
        // reads like a missing image rather than a missing architecture. Said
        // plainly, because the answer is not "check the tag".
        if (str_contains($message, 'no match for platform')) {
            return 'That image has no build for this machine\'s architecture, so it cannot run here. '
                .'`cbox doctor` says more; an arm64 tag of the image is the fix.';
        }

        return match ($reason) {
            'ImagePullBackOff', 'ErrImagePull' => 'The image could not be pulled: '
                .($message !== '' ? $message : 'check the name, the tag, and whether it needs credentials.'),
            'CreateContainerConfigError' => 'The container could not be configured, usually a binding '
                .'pointing at a Secret nothing creates: '.$message,
            'CrashLoopBackOff' => 'The container starts and exits. `cbox logs` has what it said.',
            'InvalidImageName' => 'That is not a valid image name: '.$message,
            default => null,
        };
    }
}
