<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Doctor\Doctor;
use Cbox\Engine\Enums\Severity;
use Cbox\Engine\ValueObjects\Finding;
use Illuminate\Console\Command;

/**
 * Whether this machine can run Cbox Local.
 *
 * A THIN ADAPTER, like every command here: it asks the engine and renders the
 * answer. Nothing is decided in this file, because the desktop application asks
 * the same engine the same question and the two must not be able to disagree.
 *
 * `--json` is not an afterthought for scripts. An agent is an ordinary caller of
 * this tool, and a caller that has to parse a table is a caller that breaks the
 * next time a column is widened.
 */
class DoctorCommand extends Command
{
    protected $signature = 'local:doctor {--json : Machine-readable output}';

    protected $description = 'Check that this machine can run Cbox Local';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->examine();
        $verdict = $doctor->verdict($findings);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'verdict' => $verdict->value,
                'findings' => array_map(fn (Finding $f): array => $f->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $verdict->stopsEverything() ? self::FAILURE : self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->line(match ($finding->severity) {
                Severity::Ok => "  <fg=green>✓</> {$finding->subject}: {$finding->detail}",
                Severity::Warning => "  <fg=yellow>!</> {$finding->subject}: {$finding->detail}",
                Severity::Blocked => "  <fg=red>✗</> {$finding->subject}: {$finding->detail}",
            });

            if ($finding->remedy !== '') {
                $this->line("      <fg=gray>{$finding->remedy}</>");
            }
        }

        $this->newLine();

        // The exit code carries BLOCKED and not WARNING. A warning that fails a
        // command teaches whoever wired it into a script to stop reading either.
        return $verdict->stopsEverything() ? self::FAILURE : self::SUCCESS;
    }
}
