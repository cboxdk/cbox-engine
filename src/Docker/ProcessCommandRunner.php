<?php

declare(strict_types=1);

namespace Cbox\Engine\Docker;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * The real one: Symfony Process, no shell.
 *
 * A program that is missing, or that never returns, arrives here as an
 * exception rather than an exit code — so it is turned into a result that says
 * it never ran, instead of an exit code the caller would read as the program's
 * own answer.
 */
class ProcessCommandRunner implements CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  callable(string): void  $onOutput
     */
    public function stream(array $command, callable $onOutput, ?int $timeout = 30): CommandResult
    {
        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            // Both streams, undivided. A container's own logs arrive on stdout
            // and its complaints on stderr, and interleaving them is what the
            // developer would see if they were standing in front of it.
            $exit = $process->run(static function (string $type, string $chunk) use ($onOutput): void {
                $onOutput($chunk);
            });
        } catch (ExceptionInterface $e) {
            return new CommandResult(
                ran: false,
                exitCode: -1,
                output: '',
                errorOutput: '',
                failure: $e->getMessage(),
            );
        }

        // Nothing is buffered back: it has already been handed over, and keeping
        // a second copy of a followed log is how a long-running command grows
        // until the machine notices.
        return new CommandResult(ran: true, exitCode: $exit, output: '', errorOutput: '');
    }

    /**
     * @param  list<string>  $command
     */
    public function interactive(array $command): CommandResult
    {
        $process = new Process($command);
        // No bound: the person at the keyboard decides when a shell is over.
        $process->setTimeout(null);

        try {
            // The terminal itself, not a pipe. Without this `tinker` has no line
            // editing, `Ctrl-C` kills this process instead of the program, and
            // anything that asks a question hangs waiting for an answer nobody
            // can give it.
            $process->setTty(true);
        } catch (ExceptionInterface) {
            // No terminal here — a script, a CI job, an agent. Fall back to
            // inheriting the streams so output still arrives, rather than
            // refusing to run at all.
            $process->setTty(false);
        }

        try {
            $exit = $process->run();
        } catch (ExceptionInterface $e) {
            return new CommandResult(ran: false, exitCode: -1, output: '', errorOutput: '', failure: $e->getMessage());
        }

        return new CommandResult(ran: true, exitCode: $exit, output: '', errorOutput: '');
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeout = 30, ?string $input = null): CommandResult
    {
        $process = new Process($command);
        $process->setTimeout($timeout);

        if ($input !== null) {
            $process->setInput($input);
        }

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return new CommandResult(
                ran: false,
                exitCode: -1,
                output: '',
                errorOutput: '',
                failure: $e->getMessage(),
            );
        }

        return new CommandResult(
            ran: true,
            exitCode: $process->getExitCode() ?? -1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }
}
