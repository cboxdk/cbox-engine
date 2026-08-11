<?php

declare(strict_types=1);

namespace Cbox\Engine\Tests;

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Enums\ClusterPhase;
use Cbox\Engine\ValueObjects\ClusterState;

/**
 * A cluster that remembers whether it was destroyed.
 *
 * A NAMED CLASS rather than an anonymous one, so a test can assert on
 * `$cluster->destroyed` and the analyser can see the property. An anonymous
 * class satisfies the interface and hides everything it adds.
 */
class RecordingCluster implements ClusterManager
{
    public bool $destroyed = false;

    public function up(): ClusterState
    {
        return $this->state();
    }

    public function down(): ClusterState
    {
        return $this->state();
    }

    public function destroy(): ClusterState
    {
        $this->destroyed = true;

        return new ClusterState('cbox', ClusterPhase::Absent, changed: true, context: '');
    }

    public function state(): ClusterState
    {
        return new ClusterState('cbox', ClusterPhase::Running, changed: false, context: 'kind-cbox');
    }
}
