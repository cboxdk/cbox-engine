<?php

declare(strict_types=1);

namespace Cbox\Engine\Testing;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;

/**
 * A host that answers however a test needs, and records what it was asked.
 *
 * DENY BY DEFAULT: a command nothing has been staged for comes back as one that
 * never ran, not as a success. A fake that succeeds for anything makes every
 * test above it pass while proving nothing about which command was issued — and
 * the command IS the contract here, because it is what reaches the machine.
 */
class FakeCommandRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    /**
     * What was written to each call's standard input, in the same order as
     * $calls. Manifests reach kubectl this way, so a test that does not look at
     * it cannot tell an apply of the right objects from an apply of none.
     *
     * @var list<string|null>
     */
    public array $inputs = [];

    /** @var array<string, CommandResult> */
    private array $staged = [];

    /**
     * @param  list<string>  $command
     */
    public function stage(array $command, CommandResult $result): self
    {
        $this->staged[implode(' ', $command)] = $result;

        return $this;
    }

    /** What a streamed command should emit, keyed the same way as `stage`. */
    /** @var array<string, string> */
    private array $streamed = [];

    /**
     * @param  list<string>  $command
     */
    public function willStream(array $command, string $output): self
    {
        $this->streamed[implode(' ', $command)] = $output;

        return $this;
    }

    /**
     * Recorded like any other call, and answered the same way.
     *
     * A fake that treated an interactive command as special would be a fake
     * that cannot tell a test whether the right one was issued — and the
     * command IS the contract.
     *
     * @param  list<string>  $command
     */
    public function interactive(array $command): CommandResult
    {
        return $this->run($command);
    }

    /**
     * @param  list<string>  $command
     * @param  callable(string): void  $onOutput
     */
    public function stream(array $command, callable $onOutput, ?int $timeout = 30): CommandResult
    {
        $this->calls[] = $command;
        $this->inputs[] = null;

        $key = implode(' ', $command);

        if (! isset($this->streamed[$key])) {
            return new CommandResult(
                ran: false, exitCode: -1, output: '', errorOutput: '',
                failure: 'nothing staged for: '.$key,
            );
        }

        $onOutput($this->streamed[$key]);

        return new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: '');
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeout = 30, ?string $input = null): CommandResult
    {
        $this->calls[] = $command;
        $this->inputs[] = $input;

        return $this->staged[implode(' ', $command)] ?? new CommandResult(
            ran: false,
            exitCode: -1,
            output: '',
            errorOutput: '',
            failure: 'nothing staged for: '.implode(' ', $command),
        );
    }

    /**
     * @param  list<string>  $command
     */
    public function wasRun(array $command): bool
    {
        return in_array($command, $this->calls, true);
    }
}
