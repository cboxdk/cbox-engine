<?php

declare(strict_types=1);

namespace Cbox\Engine\Host;

use Cbox\Engine\Contracts\HostResolver;
use Cbox\Engine\Kind\HostPorts;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\ValueObjects\ResolverState;

/**
 * macOS resolves a domain by reading `/etc/resolver/<domain>`.
 *
 * TWO LINES, and the second is the one that makes this whole approach work: a
 * `port` directive means the nameserver does not have to be on 53, so nothing
 * on this machine binds a privileged port and the only privileged act is writing
 * this file.
 *
 * The directory is injected so a test can write to a temporary one. A test that
 * needed `/etc` would be a test nobody runs.
 */
class MacResolver implements HostResolver
{
    public function __construct(
        private readonly HostPorts $ports = new HostPorts(
            HostPorts::HIGH_HTTP,
            HostPorts::HIGH_HTTPS,
            HostPorts::HIGH_DNS,
        ),
        private readonly string $directory = '/etc/resolver',
    ) {}

    public function path(): string
    {
        return $this->directory.'/'.ClusterObjects::DOMAIN;
    }

    public function desired(): string
    {
        // A comment naming what wrote it, because a file in /etc with no
        // explanation is one nobody dares delete years later.
        // The port is named even when it is 53, which it may be. Naming it
        // costs nothing and means this file says what it does rather than
        // relying on a default that depends on which ports were free the day
        // the cluster was built.
        return '# Written by Cbox Local. Removing this file stops *.'.ClusterObjects::DOMAIN
            ." resolving.\nnameserver 127.0.0.1\nport ".$this->ports->dns."\n";
    }

    public function state(): ResolverState
    {
        $path = $this->path();

        if (! is_file($path)) {
            return ResolverState::missing();
        }

        $found = (string) file_get_contents($path);

        // COMPARED ON WHAT IT MEANS, not byte for byte. The comment may change
        // between versions and a file that works should not be reported as
        // wrong because a sentence in it was reworded.
        return $this->directives($found) === $this->directives($this->desired())
            ? ResolverState::installed()
            : ResolverState::stale(trim($found));
    }

    /**
     * @return list<string>
     */
    public function installCommand(): array
    {
        return ['sudo', 'tee', $this->path()];
    }

    /**
     * The lines that are instructions, with comments and spacing dropped.
     *
     * @return list<string>
     */
    private function directives(string $contents): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        sort($lines);

        return $lines;
    }
}
