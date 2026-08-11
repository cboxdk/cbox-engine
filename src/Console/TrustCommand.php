<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Platform\LocalAuthority;
use Illuminate\Console\Command;

/**
 * Teach this machine's browsers to trust the local authority.
 *
 * THE LAST THING BETWEEN `curl --cacert` AND OPENING A TAB. Every certificate
 * here is real and correctly issued; the only reason a browser objects is that
 * it has never been introduced to the authority that signed them, and a
 * developer who has to click through a full-page warning to see their own
 * application stops believing the TLS is real.
 *
 * SHOWN, NOT RUN. This asks for administrator rights, and a tool that collects a
 * password to do something it has not shown you is a tool nobody should give one
 * to — the same rule as `cbox setup`. The file, the fingerprint and the exact
 * command are printed, and the person runs it.
 *
 * IT DOES NOT CLAIM THE ANSWER. This does not read the keychain, so it never
 * says "trusted" — it says where the certificate is and what to compare. A tool
 * that asserted trust it had not verified would be wrong exactly when somebody
 * is trying to work out why their browser still complains.
 */
class TrustCommand extends Command
{
    use Refuses;

    protected $signature = 'local:trust {--json : Machine-readable output}';

    protected $description = 'Show how to trust the authority that signs *.'.ClusterObjects::DOMAIN;

    public function handle(LocalAuthority $authority): int
    {
        if (! $authority->exists()) {
            return $this->refuse(
                'There is no local authority yet — it is created with the cluster. Run `cbox up` first.',
            );
        }

        $path = $authority->certificatePath();
        // The login keychain, not the System one: it needs no sudo, and a
        // development root belongs to the developer rather than to every account
        // on the machine.
        $command = 'security add-trusted-cert -d -r trustRoot '
            ."-k \"\$HOME/Library/Keychains/login.keychain-db\" {$path}";

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'certificate' => $path,
                'fingerprint' => $authority->fingerprint(),
                'command' => $command,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('  The authority that signs every *.'.ClusterObjects::DOMAIN.' certificate:');
        $this->line("      {$path}");
        $this->newLine();
        $this->line('  SHA-256, so you can check the keychain shows the same one:');
        $this->line('      '.$authority->fingerprint());
        $this->newLine();
        $this->line('  Trust it — this asks for your password, and adds nothing to the system keychain:');
        $this->line("      <options=bold>{$command}</>");
        $this->newLine();
        // Worth saying, because the alternative is somebody trusting a root and
        // then wondering why it stopped working after a rebuild.
        $this->line('  It is kept outside the cluster, so `cbox destroy` does not replace it and you');
        $this->line('  only do this once. Firefox keeps its own store and needs the file imported there.');

        return self::SUCCESS;
    }
}
