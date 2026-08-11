<?php

declare(strict_types=1);

namespace Cbox\Engine\Sandbox;

use RuntimeException;

/**
 * The PHP and Laravel versions a package is to be tried against.
 *
 * A MATRIX, because that is the actual question. "Does it work on PHP 8.4" is
 * never what anybody wants to know; "does it work on 8.3 AND 8.4 with Laravel 12
 * AND 13" is — and that is four applications, which is precisely what a laptop
 * with one PHP cannot give and precisely what a cluster can.
 */
readonly class SandboxMatrix
{
    /**
     * @param  list<string>  $php
     * @param  list<string>  $laravel
     */
    private function __construct(
        public array $php,
        public array $laravel,
    ) {}

    /**
     * Read `8.3,8.4` and `12,13`, defaulting to one combination.
     *
     * ONE BY DEFAULT, not the whole matrix: somebody typing `cbox sandbox` to
     * look at their package wants an application, and four of them is four
     * databases and four sets of dependencies to resolve before they see
     * anything.
     */
    public static function parse(string $php, string $laravel): self
    {
        return new self(
            self::versions($php, '8.4', 'php', '~^8\.\d+$~'),
            self::versions($laravel, '13', 'laravel', '~^\d+$~'),
        );
    }

    /**
     * Every combination, in the order somebody would read them.
     *
     * @return list<SandboxTarget>
     */
    public function targets(): array
    {
        $targets = [];

        foreach ($this->php as $php) {
            foreach ($this->laravel as $laravel) {
                $targets[] = new SandboxTarget($php, $laravel);
            }
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    private static function versions(string $value, string $default, string $what, string $pattern): array
    {
        $value = trim($value);

        if ($value === '') {
            return [$default];
        }

        $versions = [];

        foreach (explode(',', $value) as $version) {
            $version = trim($version);

            if (preg_match($pattern, $version) !== 1) {
                throw new RuntimeException(
                    "[{$version}] is not a {$what} version this can build a sandbox for."
                    .($what === 'php' ? ' Write it as 8.4.' : ' Write the major on its own, as 13.'),
                );
            }

            if (! in_array($version, $versions, true)) {
                $versions[] = $version;
            }
        }

        return $versions;
    }
}
