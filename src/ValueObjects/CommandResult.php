<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/**
 * What a program on the host did.
 *
 * `ran` is not the same as `succeeded`, and conflating them is how a missing
 * binary gets reported as a failing one. A program that is not installed never
 * ran and has no exit code worth reading; a program that ran and exited 1 has
 * output that says why. The two need different sentences in front of a human.
 */
readonly class CommandResult
{
    public function __construct(
        public bool $ran,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        /** Why it could not be started, when `ran` is false. */
        public string $failure = '',
    ) {}

    public function successful(): bool
    {
        return $this->ran && $this->exitCode === 0;
    }

    /** Output with trailing whitespace removed, which is what every caller wants. */
    public function text(): string
    {
        return trim($this->output);
    }
}
