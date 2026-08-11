<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use RuntimeException;

/**
 * A process's command, as the arguments a container will actually be given.
 *
 * THERE IS NO SHELL, and that is the whole design rather than an inconvenience.
 * `ProcessSpec` takes a list of arguments because a container runs a program,
 * not a shell line — so nothing a manifest says can be made to run a second
 * command by a value that happens to contain a semicolon.
 *
 * But writing every command as a YAML list is a tax on the ordinary case, and a
 * developer tool that charges it will be worked around. So a string is accepted
 * and split HERE, carefully, with quotes honoured:
 *
 *     php artisan queue:work --queue="high,low"
 *
 * And anything that genuinely needs a shell is REFUSED rather than silently
 * mangled. `a && b` split naively becomes a program called `a` with an argument
 * called `&&`, which fails at runtime with a message about a missing file — so
 * the refusal names what is wrong and what to do instead.
 */
readonly class CommandLine
{
    /** The characters that only mean anything to a shell. */
    private const SHELL_ONLY = ['|', '&', ';', '>', '<', '`', '$', "\n"];

    /**
     * @param  string|array<mixed>  $command
     * @return list<string>
     */
    public static function parse(string|array $command, string $process): array
    {
        if (is_array($command)) {
            $arguments = array_values(array_filter($command, is_string(...)));

            if ($arguments === []) {
                throw new RuntimeException("The process [{$process}] has no command to run.");
            }

            return $arguments;
        }

        if (trim($command) === '') {
            throw new RuntimeException("The process [{$process}] has no command to run.");
        }

        foreach (self::SHELL_ONLY as $character) {
            if (str_contains($command, $character)) {
                throw new RuntimeException(
                    "The command for [{$process}] contains [".trim($character).'], which only means '
                    .'something to a shell — and a container runs a program, not a shell. Put the '
                    ."shell in your image's entrypoint, or split the work into two processes."
                );
            }
        }

        $arguments = self::split($command);

        if ($arguments === []) {
            throw new RuntimeException("The process [{$process}] has no command to run.");
        }

        return $arguments;
    }

    /**
     * Split on whitespace, honouring single and double quotes.
     *
     * Written rather than borrowed because the alternatives all reach for a
     * shell to do it, which is the one thing this must not do.
     *
     * @return list<string>
     */
    private static function split(string $command): array
    {
        $arguments = [];
        $current = '';
        $quote = null;
        $started = false;

        foreach (str_split($command) as $character) {
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;

                    continue;
                }

                $current .= $character;

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                // An empty quoted string is still an argument: `--flag=""` must
                // not vanish.
                $started = true;

                continue;
            }

            if ($character === ' ' || $character === "\t") {
                if ($started) {
                    $arguments[] = $current;
                    $current = '';
                    $started = false;
                }

                continue;
            }

            $current .= $character;
            $started = true;
        }

        if ($quote !== null) {
            throw new RuntimeException(
                'A command ends inside an unclosed quote, so it is not clear where one argument '
                .'stops and the next begins.'
            );
        }

        if ($started) {
            $arguments[] = $current;
        }

        return $arguments;
    }
}
