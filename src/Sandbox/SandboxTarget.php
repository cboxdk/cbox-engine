<?php

declare(strict_types=1);

namespace Cbox\Engine\Sandbox;

/**
 * One combination of PHP and Laravel, and everything named after it.
 */
readonly class SandboxTarget
{
    public function __construct(
        public string $php,
        public string $laravel,
    ) {}

    /**
     * The environment name, and therefore the namespace and the hostname.
     *
     * A DNS LABEL, so `8.3` becomes `83`: dots are separators in a hostname and
     * `php8.3-laravel12.pkg.cbox.test` is a different name in a different place
     * from the one anybody meant.
     */
    public function environment(): string
    {
        return 'php'.str_replace('.', '', $this->php).'-laravel'.$this->laravel;
    }

    /**
     * The DEV TIER, and that is the point of a sandbox.
     *
     * Xdebug, SPX and pcov are in it, and a package author is exactly the person
     * who wants to step through a request. The standard tier is what an
     * application ships on; this is not an application.
     */
    public function image(): string
    {
        return 'ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:'.$this->php.'-bookworm-dev';
    }

    /** The Laravel constraint composer is asked for. */
    public function constraint(): string
    {
        return '^'.$this->laravel.'.0';
    }
}
