<?php

declare(strict_types=1);

namespace Cbox\Engine\Contracts;

use Cbox\Engine\ValueObjects\CommandResult;

/**
 * Running a program on the developer's machine.
 *
 * An interface because it is the ONLY place this application touches the host,
 * and everything above it — detecting a container runtime, creating a cluster,
 * tailing a log — is then testable without one. A test that needs Docker
 * installed to run is a test that will be skipped.
 *
 * It takes an argument LIST and never a string, so nothing here can be made to
 * run a second command by a path with a space or a project named `; rm`. There
 * is no shell in the middle to be tricked.
 */
interface CommandRunner
{
    /**
     * @param  list<string>  $command  the program and its arguments, unescaped
     * @param  int  $timeout  seconds before the program is killed; a developer
     *                        tool that hangs is indistinguishable from a broken
     *                        machine, so there is no unbounded call
     * @param  string|null  $input  written to the program's standard input.
     *                              Manifests arrive this way rather than as a
     *                              file, because a file would have to exist
     *                              somewhere both this process and a container
     *                              can see — and the two do not share a
     *                              filesystem.
     */
    public function run(array $command, int $timeout = 30, ?string $input = null): CommandResult;

    /**
     * Run a program and hand its output over as it arrives.
     *
     * A SEPARATE METHOD BECAUSE THE SHAPE IS DIFFERENT, not because the plumbing
     * is. `run()` answers "what did this program say" and can only do that once
     * the program has stopped saying it; following a log is the case where the
     * answer never comes and the output is the point.
     *
     * The timeout is NULLABLE here alone. Everything else in this application
     * has a bound because a developer tool that hangs is indistinguishable from
     * a broken machine — but a follow is unbounded by definition, and the person
     * who started it ends it.
     *
     * @param  list<string>  $command
     * @param  callable(string): void  $onOutput  called with each chunk, in order
     */
    public function stream(array $command, callable $onOutput, ?int $timeout = 30): CommandResult;

    /**
     * Hand this terminal over to the program until it is finished.
     *
     * A THIRD SHAPE, and it earns its place: `tinker`, `psql`, `bash` and a
     * migration that asks "are you sure" all need a real terminal — line
     * editing, arrow keys, Ctrl-C reaching the program rather than this process.
     * `stream()` can show output and cannot take input, and a developer tool
     * that cannot open a shell is one they will keep `kubectl` around for.
     *
     * Unbounded by definition: the person who started it ends it.
     *
     * @param  list<string>  $command
     */
    public function interactive(array $command): CommandResult;
}
