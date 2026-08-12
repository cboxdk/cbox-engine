<?php

declare(strict_types=1);

namespace Cbox\Engine\Support;

use RuntimeException;

/**
 * Where this user's home directory is — asked once, the same way everywhere.
 *
 * BECAUSE `HOME` IS A SHELL CONVENTION, NOT A GUARANTEE. It is always set for a
 * command somebody typed and routinely absent in a web process: php-fpm and
 * `artisan serve` both hand a request an environment without it. The desktop
 * application is the same engine behind a window, so every path derived from
 * `HOME` was wrong there — and the ways it was wrong were worse than an error.
 * `rtrim('', '/').'/.cbox/ca'` is `/.cbox/ca`, at the root of the filesystem,
 * which no exception announces and nothing refuses.
 *
 * FOUR PLACES READ IT AND EACH ANSWERED DIFFERENTLY — one threw, one built a
 * root-level path, one silently dropped the `~`. This is one answer, so a fix
 * lands everywhere at once and a new caller cannot invent a fifth behaviour.
 *
 * THE PASSWD DATABASE IS THE FALLBACK, because it holds the fact `HOME` is
 * usually a copy of. Refused only when neither knows: a user with no home
 * directory at all is one where there is genuinely nowhere to put anything.
 */
final class Home
{
    /** @throws RuntimeException when neither the environment nor the OS knows */
    public static function directory(): string
    {
        $home = Env::string('HOME', '');

        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $account = posix_getpwuid(posix_geteuid());
            $home = $account === false ? '' : $account['dir'];
        }

        $home = rtrim($home, '/');

        // `/` is refused as hard as an empty string: it is what an unset HOME
        // looks like after a naive rtrim, and it turns "the developer's files"
        // into "the entire filesystem" — which for a tool that mounts paths into
        // a cluster node is the one outcome worth refusing outright.
        if ($home === '') {
            throw new RuntimeException(
                'There is no home directory for this user — neither HOME nor the passwd database names '
                .'one — so there is nowhere to keep this machine\'s Cbox files.',
            );
        }

        return $home;
    }

    /** Expand a leading `~/`, leaving every other path exactly as it was. */
    public static function expand(string $path): string
    {
        return str_starts_with($path, '~/')
            ? self::directory().substr($path, 1)
            : $path;
    }
}
