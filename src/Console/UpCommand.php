<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Addons\AddonInstaller;
use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Contracts\HttpProbe;
use Cbox\Engine\Doctor\Doctor;
use Cbox\Engine\Enums\ClusterPhase;
use Cbox\Engine\Enums\Severity;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Kind\PublishedPorts;
use Illuminate\Console\Command;

/**
 * Bring the local cluster up.
 *
 * IT CHECKS THE MACHINE FIRST, and refuses rather than trying. Without a running
 * container runtime `kind create cluster` fails somewhere deep in its own
 * plumbing, minutes later, with an error about a socket — when the answer was
 * knowable in fifteen milliseconds and is "start OrbStack".
 *
 * A warning does not stop it. What doctor reports is a finding about the machine,
 * not a verdict on whether the cluster can come up, and most findings are worth
 * saying once rather than refusing over.
 */
class UpCommand extends Command
{
    protected $signature = 'local:up {--json : Machine-readable output}';

    protected $description = 'Bring the local cluster up, creating it if it is not there';

    /**
     * Wait until the address a developer will actually type answers.
     *
     * MEASURED, and it is the reason this exists rather than a Deployment wait.
     * After a stop and start, every structural signal said the gateway was
     * ready — its Deployment Available, its Gateway Programmed, its pods 2/2 —
     * while requests to it could not connect for well over a minute. `up`
     * reported success in six seconds, and a developer who opened their browser
     * saw nothing and concluded the tool was broken.
     *
     * Any answer counts. A 404 is a healthy gateway with no route for that
     * hostname, which is exactly what an empty platform should say; waiting for
     * a 200 would mean waiting for somebody to deploy something.
     */
    private function waitForGateway(HttpProbe $probe, HostPorts $ports, int $seconds = 240): bool
    {
        $url = 'http://127.0.0.1:'.$ports->http.'/';

        for ($waited = 0; $waited < $seconds; $waited += 2) {
            if ($probe->answers($url)) {
                return true;
            }

            sleep(2);
        }

        return false;
    }

    public function handle(
        Doctor $doctor,
        ClusterManager $cluster,
        AddonInstaller $addons,
        HttpProbe $probe,
        PublishedPorts $published,
    ): int {
        $findings = $doctor->examine();

        if ($doctor->verdict($findings)->stopsEverything()) {
            foreach ($findings as $finding) {
                if ($finding->severity === Severity::Blocked) {
                    $this->error("  {$finding->subject}: {$finding->detail}");
                    $this->line("      {$finding->remedy}");
                }
            }

            return self::FAILURE;
        }

        // Said only when it is TRUE. Printing "this takes a few minutes" in
        // front of an operation that takes under a second is the kind of small
        // lie that teaches people to stop reading the output.
        $before = $cluster->state()->phase;

        if (! $this->option('json') && $before === ClusterPhase::Absent) {
            $this->line('  Building the cluster. The first run takes a few minutes.');
        }

        $state = $cluster->up();

        if ($this->option('json')) {
            $this->line((string) json_encode($state->toArray(), JSON_PRETTY_PRINT));

            return $state->running() ? self::SUCCESS : self::FAILURE;
        }

        if (! $state->running()) {
            $this->error("  {$state->failure}");

            return self::FAILURE;
        }

        // A cluster that was ALREADY up and one that was just built both end
        // here, and after a long wait the difference is the only thing worth
        // reading.
        // Three states, three sentences. Built, started and already running are
        // three different waits — minutes, seconds and none — and telling a
        // person which one they are in is most of what output is for.
        $this->line(match (true) {
            ! $state->changed => "  Cluster [{$state->name}] was already running.",
            $before === ClusterPhase::Absent => "  Cluster [{$state->name}] built.",
            default => "  Cluster [{$state->name}] started.",
        });

        // THE PLATFORM, not just the cluster. Installing the gateway was a
        // separate command while it was being built; a developer should never
        // have to know the step exists. Server-side apply converges, so running
        // it every time changes nothing on a cluster that already has it.
        $results = $addons->install();

        foreach ($results as $result) {
            if (! $result->succeeded) {
                $this->error("  {$result->name} could not be installed.");
                $this->line("      {$result->failure}");

                return self::FAILURE;
            }
        }

        $ports = $published->current();

        if (! $this->waitForGateway($probe, $ports)) {
            $this->error('  The cluster is up, but its gateway is not answering.');
            $this->line('      Try `cbox status`, and `cbox addons` to re-install the platform.');

            return self::FAILURE;
        }

        $this->line("  <fg=green>✓</> Cluster [{$state->name}] is up and serving.");
        $this->line("      kubectl context: {$state->context}");

        if (! $ports->isPrivileged()) {
            // Said once, here, rather than left for somebody to discover in a
            // browser: this cluster was built while something else held 80 and
            // 443, and its addresses carry a port because of it.
            $this->line("      Ports {$ports->http} and {$ports->https}: something else held 80 and 443 when this");
            $this->line('      cluster was built. `cbox destroy` and `cbox up` takes them if they are free now.');
        }

        return self::SUCCESS;
    }
}
