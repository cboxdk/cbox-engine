<?php

declare(strict_types=1);

namespace Cbox\Engine\Contracts;

use Cbox\Engine\ValueObjects\ClusterState;

/**
 * The local Kubernetes cluster everything else runs in.
 *
 * ONE CLUSTER PER MACHINE, not one per project, and that is a decision rather
 * than a simplification. It is the same shape as production — a cell holds many
 * tenants, each in its own namespace — so a project is a namespace here too, and
 * what a developer learns about isolation is true in both places.
 *
 * It is also the only shape a laptop can carry. A cluster per project means a
 * control plane, a CNI and a node container per project; fifteen of those is not
 * a development environment, it is a heater.
 *
 * An interface because kind is not the only way to get one — k3d and a runtime's
 * own built-in Kubernetes exist, and Linux and Windows will each have opinions.
 */
interface ClusterManager
{
    /** Create it if it is not there, start it if it is stopped. Idempotent. */
    public function up(): ClusterState;

    /** Stop it without destroying it: the next `up` is fast. */
    public function down(): ClusterState;

    /** Destroy it. Everything in it goes. */
    public function destroy(): ClusterState;

    public function state(): ClusterState;
}
