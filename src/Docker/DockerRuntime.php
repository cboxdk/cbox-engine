<?php

declare(strict_types=1);

namespace Cbox\Engine\Docker;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Contracts\ContainerRuntime;
use Cbox\Engine\Enums\Architecture;
use Cbox\Engine\ValueObjects\RuntimeStatus;

/**
 * Anything that speaks the Docker CLI: OrbStack, Docker Desktop, colima, Rancher.
 *
 * ONE CALL, and it has to be `docker info` rather than `docker version`.
 * `docker version` answers from the client alone when no daemon is listening, so
 * a stopped runtime looks installed and healthy. `docker info` needs the server,
 * which is the thing actually being asked about.
 *
 * The runtime NAMES ITSELF in that answer — OrbStack reports `OperatingSystem:
 * OrbStack` — which is how a message can say "OrbStack is not running" rather
 * than the generic sentence that sends people to download something they have.
 */
class DockerRuntime implements ContainerRuntime
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function probe(): RuntimeStatus
    {
        $result = $this->runner->run([
            'docker', 'info', '--format', '{{.OperatingSystem}}|{{.Architecture}}|{{.ServerVersion}}',
        ], timeout: 15);

        if (! $result->ran) {
            return RuntimeStatus::missing($result->failure);
        }

        if (! $result->successful()) {
            // The client is installed — it answered — but the daemon is not
            // there. Its own words, because "Cannot connect to the Docker daemon"
            // is more use than anything this could invent.
            return RuntimeStatus::stopped(trim($result->errorOutput) ?: $result->text());
        }

        $parts = explode('|', $result->text());

        return RuntimeStatus::running(
            name: trim($parts[0] ?? '') ?: 'Docker',
            version: trim($parts[2] ?? ''),
            architecture: Architecture::fromRuntime($parts[1] ?? ''),
        );
    }
}
