<?php

declare(strict_types=1);

namespace Cbox\Engine\Doctor;

use Cbox\Engine\Contracts\ContainerRuntime;
use Cbox\Engine\Contracts\HostResolver;
use Cbox\Engine\Enums\Architecture;
use Cbox\Engine\Enums\Severity;
use Cbox\Engine\ValueObjects\Finding;

/**
 * Whether this machine can run Cbox Local, and what to do where it cannot.
 *
 * The first command anybody types and the only one that can explain why the rest
 * do not work. Everything it reports was measured on the machine in front of it;
 * nothing is inferred from what is usually true.
 *
 * ORDERED BY DEPENDENCY, not by importance. Without a running container runtime
 * there is nothing to ask about architectures or clusters, so those are not
 * guessed at — they are simply absent, and the one answer that matters is at the
 * top.
 */
class Doctor
{
    public function __construct(
        private readonly ContainerRuntime $runtime,
        private readonly HostResolver $resolver,
    ) {}

    /**
     * @return list<Finding>
     */
    public function examine(): array
    {
        $status = $this->runtime->probe();

        if (! $status->installed) {
            return [Finding::blocked(
                'Container runtime',
                'No container runtime answered on this machine.'
                    .($status->failure !== '' ? ' ('.$status->failure.')' : ''),
                'Install OrbStack or Docker Desktop, then run this again.',
            )];
        }

        if (! $status->running) {
            return [Finding::blocked(
                'Container runtime',
                'A container runtime is installed but is not running.'
                    .($status->failure !== '' ? ' ('.$status->failure.')' : ''),
                'Start it, wait for it to report ready, then run this again.',
            )];
        }

        return [
            Finding::ok('Container runtime', "{$status->name} {$status->version} is running."),
            $this->architecture($status->architecture),
            $this->resolution(),
        ];
    }

    /**
     * The one finding that is neither good news nor bad news.
     *
     * The Cbox base images are published for linux/amd64 only. On Apple Silicon
     * the production image therefore runs under emulation: it is the same image
     * and it behaves the same, which is the point — but it is slower, and a
     * developer who is not told will reasonably conclude the tool is slow.
     *
     * Stated as a warning rather than hidden, and rather than treated as a
     * failure: an emulated image still proves everything about proxy headers,
     * routing, processes and scaling that this product exists to prove.
     */
    private function architecture(Architecture $architecture): Finding
    {
        if ($architecture === Architecture::Amd64) {
            return Finding::ok('Architecture', 'amd64. The Cbox base images are built for it.');
        }

        if ($architecture === Architecture::Arm64) {
            // WAS A WARNING UNTIL THE IMAGES EXISTED, and a stale warning is
            // worse than none: it tells somebody their machine is the problem
            // long after it stopped being. The PHP tiers are built natively for
            // arm64 now; `cboxdk/percona` is the one that is not, and a project
            // that asks for MySQL is told so where it happens rather than here,
            // where it would be a warning most people do not need.
            return Finding::ok('Architecture', 'arm64. The Cbox base images are built for it natively.');
        }

        return Finding::warning(
            'Architecture',
            'The container runtime did not report an architecture this understands.',
            'Cbox Local will still run. Report the output of `docker info` so this can name it.',
        );
    }

    /**
     * Whether a project's hostname will resolve in a browser.
     *
     * THE MOST CONFUSING FAILURE THIS PRODUCT HAS. Everything else can be
     * perfect — the cluster up, the gateway serving, the certificate valid — and
     * the developer still gets nothing, because their machine has never been
     * told where to ask about the domain. `curl --resolve` works, which makes it
     * worse: the thing they try first in order to debug it succeeds.
     *
     * A warning rather than a block. Everything except opening a browser works
     * without it, and refusing to bring a cluster up over a resolver file would
     * be the tool deciding what somebody is allowed to do next.
     */
    private function resolution(): Finding
    {
        $state = $this->resolver->state();

        if ($state->current) {
            return Finding::ok('Hostnames', 'This machine resolves the development domain.');
        }

        if ($state->present) {
            return Finding::warning(
                'Hostnames',
                'The resolver file exists and points somewhere else, so hostnames resolve to '
                    .'something other than this cluster.',
                'Run `cbox setup` — it shows what it would change before changing it.',
            );
        }

        return Finding::warning(
            'Hostnames',
            'This machine has not been told where to ask about the development domain, so a '
                .'project opens in curl and not in a browser.',
            'Run `cbox setup`. One file, once, and it asks before writing.',
        );
    }

    /**
     * The worst thing found, for a caller that wants one answer.
     *
     * @param  list<Finding>  $findings
     */
    public function verdict(array $findings): Severity
    {
        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Blocked) {
                return Severity::Blocked;
            }
        }

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Warning) {
                return Severity::Warning;
            }
        }

        return Severity::Ok;
    }
}
