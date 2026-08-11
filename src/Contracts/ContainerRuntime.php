<?php

declare(strict_types=1);

namespace Cbox\Engine\Contracts;

use Cbox\Engine\ValueObjects\RuntimeStatus;

/**
 * The container runtime a local cluster is built on.
 *
 * An interface because there is more than one on macOS alone — OrbStack, Docker
 * Desktop, colima — and because Linux and Windows will each bring their own. The
 * cluster layer above must never learn which one it got.
 */
interface ContainerRuntime
{
    public function probe(): RuntimeStatus;
}
