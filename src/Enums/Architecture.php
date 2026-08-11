<?php

declare(strict_types=1);

namespace Cbox\Engine\Enums;

/**
 * The processor a container will actually run on.
 *
 * It matters here for one measured reason: the Cbox base images are published
 * for linux/amd64 only, and every machine this is being built on is Apple
 * Silicon. On arm64 the production image therefore runs under emulation —
 * correct, but slower and stranger than the thing it is supposed to be identical
 * to. That is worth SAYING to the developer rather than letting them wonder why
 * their container is sluggish.
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

    /** What a container image has to be built for to run natively here. */
    public function platform(): string
    {
        return 'linux/'.$this->value;
    }
}
