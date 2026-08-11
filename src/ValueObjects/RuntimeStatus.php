<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

use Cbox\Engine\Enums\Architecture;

/**
 * What is answering as the container runtime on this machine, if anything.
 *
 * THREE STATES AND NOT TWO. A runtime that is not installed, and one that is
 * installed but not running, need different sentences: the first is a download
 * and the second is opening an application that is already there. Reporting both
 * as "Docker is not available" sends half the people who see it to the wrong
 * place.
 */
readonly class RuntimeStatus
{
    private function __construct(
        public bool $installed,
        public bool $running,
        /** What it calls itself: OrbStack, Docker Desktop, colima… */
        public string $name,
        public string $version,
        public Architecture $architecture,
        /** Why it is not answering, when it is not. */
        public string $failure = '',
    ) {}

    public static function missing(string $failure): self
    {
        return new self(false, false, '', '', Architecture::Unknown, $failure);
    }

    public static function stopped(string $failure): self
    {
        return new self(true, false, '', '', Architecture::Unknown, $failure);
    }

    public static function running(string $name, string $version, Architecture $architecture): self
    {
        return new self(true, true, $name, $version, $architecture);
    }
}
