<?php

declare(strict_types=1);

namespace Cbox\Engine\Contracts;

use Cbox\Engine\ValueObjects\ResolverState;

/**
 * Teaching the machine where to ask about this development domain.
 *
 * THE ONLY PRIVILEGED THING IN THIS PRODUCT, and it is one file, once. Every
 * other design needs the password more often or holds it longer: an
 * `/etc/hosts` entry per project is a prompt on every deploy, and a nameserver
 * on port 53 is something that must bind a privileged port and stay bound.
 *
 * BEHIND A CONTRACT because it is the most platform-specific thing here. macOS
 * has `/etc/resolver/<domain>` and a `port` directive; Linux has systemd-resolved
 * with different words for the same idea; Windows has neither. One
 * implementation today, and adding another should be adding an implementation
 * rather than finding where the assumption was buried.
 */
interface HostResolver
{
    public function state(): ResolverState;

    /** What the file should contain, for a command that has to show it. */
    public function desired(): string;

    /** Where it goes. */
    public function path(): string;

    /**
     * Whether a name under the development domain actually resolves here.
     *
     * THE OUTCOME, NOT THE ARTEFACT, and the difference is not academic. The
     * file this writes is one way a machine learns the domain and not the only
     * one: any resolver covering the parent `.test` — another local development
     * tool, a dnsmasq somebody set up years ago — answers for it too.
     *
     * Measured on a machine with exactly that. `/etc/resolver/cbox.test` was
     * absent, so doctor reported "this machine has not been told where to ask"
     * and warned that projects would open in curl and not in a browser. They
     * opened in a browser the whole time. A checker that reads its own file and
     * infers the world from it is the thing this class exists not to be.
     */
    public function resolves(): bool;

    /**
     * The exact command a person would run to write it.
     *
     * Returned rather than executed, because writing it needs a password this
     * process should not be collecting. A tool that asks for a password to do
     * something it will not show you is a tool nobody should trust.
     *
     * @return list<string>
     */
    public function installCommand(): array;
}
