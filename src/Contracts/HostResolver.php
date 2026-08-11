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
