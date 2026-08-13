<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\ClusterManager;
use Cbox\Engine\Enums\ClusterPhase;
use Cbox\Engine\Project\ProjectRegistry;
use Cbox\Engine\Tunnel\TunnelRegistry;
use Cbox\Engine\ValueObjects\ProjectState;
use Illuminate\Console\Command;

/**
 * What the local cluster is right now.
 *
 * Reports `stopped` as its own answer rather than folding it into "not running",
 * because the two lead to different next commands: one is `cbox up` and takes
 * seconds, the other is `cbox up` and takes minutes.
 */
class StatusCommand extends Command
{
    protected $signature = 'local:status {--json : Machine-readable output}';

    protected $description = 'Show the state of the local cluster';

    public function handle(ClusterManager $cluster, ProjectRegistry $projects, TunnelRegistry $tunnels): int
    {
        $state = $cluster->state();

        // Only asked when there is something to ask. A stopped cluster answers
        // nothing, and a list of projects that reads as empty because the
        // cluster is down is a list that lies.
        $deployed = $state->running() ? $projects->all() : [];

        // A public address into this machine is the one piece of state here that
        // is a security question rather than a convenience, so it is shown
        // whether or not anybody asked.
        $exposed = $state->running() ? $tunnels->running() : [];

        if ($this->option('json')) {
            $this->line((string) json_encode($state->toArray() + [
                'projects' => array_map(
                    static fn (ProjectState $p): array => $p->toArray() + ['exposed' => $exposed[$p->name] ?? null],
                    $deployed,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(match ($state->phase) {
            ClusterPhase::Running => "  <fg=green>●</> [{$state->name}] running — kubectl context {$state->context}",
            ClusterPhase::Stopped => "  <fg=yellow>○</> [{$state->name}] stopped. `cbox up` starts it in seconds.",
            ClusterPhase::Absent => "  <fg=gray>○</> [{$state->name}] does not exist. `cbox up` builds it.",
            // THE REASON WHEN THERE IS ONE, and the question only when there is
            // not. "Is the container runtime running?" is the right guess when
            // kind ran and failed, and a wrong accusation when kind never
            // started — see {@see \Cbox\Engine\Kind\KindCluster::phase()}.
            ClusterPhase::Unknown => $state->failure !== ''
                ? "  <fg=red>?</> Could not tell — {$state->failure}"
                : '  <fg=red>?</> Could not tell — is the container runtime running?',
        });

        if ($deployed === []) {
            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($deployed as $project) {
            // ASLEEP AND IDLE ARE DIFFERENT, and saying so is the point. Both
            // show no web pod: one was put away deliberately and its database is
            // hibernated, the other will answer the next request in about two
            // seconds. Reporting them the same way makes somebody wake what was
            // never asleep.
            $this->line(match (true) {
                $project->asleep() => "  <fg=gray>○</> {$project->name} — asleep. `cbox wake` brings it back.",
                $project->idle() => "  <fg=yellow>◐</> {$project->name} — idle, wakes on the next request.",
                $project->degraded() => "  <fg=red>●</> {$project->name} — {$project->running}/{$project->wanted} running. `cbox logs` says why.",
                default => "  <fg=green>●</> {$project->name} — {$project->running}/{$project->wanted} running.",
            });

            if (array_key_exists($project->name, $exposed)) {
                $address = $exposed[$project->name];

                $this->line($address === ''
                    ? '      <fg=yellow>↗</> exposed to the internet. `cbox unexpose` closes it.'
                    : "      <fg=yellow>↗</> {$address} — `cbox unexpose` closes it.");
            }
        }

        return self::SUCCESS;
    }
}
