<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Contracts\HostResolver;
use Cbox\Engine\Platform\ClusterObjects;
use Illuminate\Console\Command;

/**
 * The one thing this product needs a password for, done once.
 *
 * SHOWN BEFORE IT IS RUN, always. This asks for administrator rights, and a tool
 * that collects a password to do something it has not shown you is a tool nobody
 * should give one to. The file, its contents and the exact command are printed
 * first, every time, including when the answer is yes.
 *
 * It is one file. `/etc/resolver/<domain>` is how macOS is told where to ask
 * about a domain, and a `port` directive in it means the nameserver can live on
 * an unprivileged port inside the cluster — so nothing here binds 53, nothing
 * stays running as root, and there is no per-project `/etc/hosts` entry to add
 * on every deploy.
 */
class SetupCommand extends Command
{
    protected $signature = 'local:setup {--json : Machine-readable output}';

    protected $description = 'Teach this machine to resolve *.'.ClusterObjects::DOMAIN.' (asks for a password, once)';

    public function handle(HostResolver $resolver, CommandRunner $runner): int
    {
        $state = $resolver->state();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'path' => $resolver->path(),
                'present' => $state->present,
                'current' => $state->current,
                'command' => implode(' ', $resolver->installCommand()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $state->current ? self::SUCCESS : self::FAILURE;
        }

        if ($state->current) {
            $this->line('  <fg=green>✓</> This machine already resolves *.'.ClusterObjects::DOMAIN.'.');
            $this->line("      {$resolver->path()}");

            return self::SUCCESS;
        }

        if ($state->present) {
            // The strangest symptom this product can produce: a hostname that
            // resolves to nothing while everything reports healthy. Said out
            // loud rather than silently overwritten.
            $this->line("  <fg=yellow>!</> {$resolver->path()} exists and points somewhere else:");

            foreach (preg_split('/\R/', $state->found) ?: [] as $line) {
                $this->line("        {$line}");
            }

            $this->newLine();
        }

        $this->line('  This needs administrator rights, once, to write one file:');
        $this->newLine();
        $this->line("      <fg=cyan>{$resolver->path()}</>");
        $this->newLine();

        foreach (preg_split('/\R/', trim($resolver->desired())) ?: [] as $line) {
            $this->line("        {$line}");
        }

        $this->newLine();
        $this->line('  It tells macOS to ask the cluster about that domain, and nothing else.');
        $this->line('  Nothing here binds a privileged port, and nothing stays running as root.');
        $this->newLine();

        if (! $this->input->isInteractive()) {
            // Not a refusal to work — the exact command, so a script or an agent
            // can hand it to somebody who can answer.
            // A heredoc rather than a quoted string: the contents are several
            // lines, and a one-liner that has to escape them is one somebody
            // pastes wrongly at the exact moment they are already annoyed.
            $this->line('  Nobody here to ask for a password. Run this:');
            $this->newLine();
            $this->line('      <fg=cyan>'.implode(' ', $resolver->installCommand())." >/dev/null <<'EOF'</>");

            foreach (preg_split('/\R/', trim($resolver->desired())) ?: [] as $line) {
                $this->line("      <fg=cyan>{$line}</>");
            }

            $this->line('      <fg=cyan>EOF</>');

            return self::FAILURE;
        }

        if (! $this->confirm('Write it?', default: true)) {
            $this->line('  Left alone. Hostnames will not resolve until this exists.');

            return self::FAILURE;
        }

        // `sudo tee`, so the password prompt is sudo's own and this process
        // never sees or holds it.
        $written = $runner->run($resolver->installCommand(), timeout: 120, input: $resolver->desired());

        if (! $written->successful()) {
            $this->error('  It was not written.');
            $this->line('      '.(trim($written->errorOutput) ?: $written->failure));

            return self::FAILURE;
        }

        $this->line('  <fg=green>✓</> Done. *.'.ClusterObjects::DOMAIN.' resolves to this machine.');

        return self::SUCCESS;
    }
}
