<?php

declare(strict_types=1);

namespace Cbox\Engine\Enums;

/**
 * Where the local cluster is.
 *
 * `Stopped` is a real state and not a variety of absent: a kind cluster whose
 * containers are not running still holds every volume, image and object in it,
 * and starting it is seconds where creating it is minutes. Reporting the two the
 * same way is how a developer loses a database to a command they thought was
 * safe.
 */
enum ClusterPhase: string
{
    case Absent = 'absent';
    case Stopped = 'stopped';
    case Running = 'running';
    case Unknown = 'unknown';
}
