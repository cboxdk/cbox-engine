<?php

declare(strict_types=1);

namespace Cbox\Engine\Enums;

use Cbox\Engine\Doctor\Doctor;

/**
 * The processor a container will actually run on.
 *
 * IT MATTERED MORE THAN IT DOES. The Cbox base images were once published for
 * linux/amd64 only, and every machine this is built on is Apple Silicon, so the
 * production image ran emulated: correct, but slower and stranger than the thing
 * it is meant to be identical to. They are built natively for arm64 now, and
 * saying otherwise would tell somebody their machine is the problem long after
 * it stopped being — see {@see Doctor}.
 *
 * What remains is naming the architecture at all, for the one error that reads
 * like a missing image and is really a missing build ("no match for platform").
 *
 * Docker reports the host's architecture in several spellings; they are mapped
 * here once rather than compared at each call site.
 */
enum Architecture: string
{
    case Arm64 = 'arm64';
    case Amd64 = 'amd64';
    case Unknown = 'unknown';

    public static function fromRuntime(string $reported): self
    {
        return match (strtolower(trim($reported))) {
            'aarch64', 'arm64', 'arm64/v8' => self::Arm64,
            'x86_64', 'amd64', 'x86-64' => self::Amd64,
            default => self::Unknown,
        };
    }
}
