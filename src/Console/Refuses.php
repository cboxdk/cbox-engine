<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Illuminate\Console\Command;

/**
 * Saying no in the shape the caller asked for.
 *
 * `--json` EXISTS SO AN AGENT DOES NOT HAVE TO GUESS, and a command that
 * answers a refusal in prose has broken that for exactly the case where it
 * matters: an agent handles the happy path fine and then meets
 * `Syntax error` from its own JSON parser when something goes wrong. It cannot
 * tell "the cluster is down" from "the tool crashed", which are different
 * things to do next.
 *
 * So a refusal under `--json` is a document with an `error` in it, and nothing
 * else on the stream.
 *
 * @phpstan-require-extends Command
 */
trait Refuses
{
    /**
     * @param  array<string, mixed>  $context  anything else the caller can act on
     */
    protected function refuse(string $message, array $context = []): int
    {
        if ($this->wantsJson()) {
            $this->line((string) json_encode(
                ['error' => $message] + $context,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return Command::FAILURE;
        }

        $this->error('  '.$message);

        return Command::FAILURE;
    }

    /**
     * A second line under a refusal — what to do about it.
     *
     * Dropped entirely under `--json`, where it would be a second document on
     * the same stream. The message itself carries what matters.
     */
    protected function remedy(string $line): void
    {
        if ($this->wantsJson()) {
            return;
        }

        $this->line('      '.$line);
    }

    /**
     * Whether this command was asked for JSON at all.
     *
     * `cbox logs` and `cbox run` have no `--json` — their output IS the
     * program's — and the analyser is right to refuse a call for an option that
     * cannot exist. The definition is the authority, not a guess.
     */
    private function wantsJson(): bool
    {
        // Through the input rather than `$this->option()`: the sugar is checked
        // against each command's own signature, and this trait is used by two
        // that have no `--json` at all — `logs` and `run`, whose output IS the
        // program's. The definition is asked first either way.
        return $this->getDefinition()->hasOption('json') && $this->input->getOption('json') === true;
    }
}
