<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

/**
 * Which copy of a project this is.
 *
 * A git worktree is an environment, and that is not a local convenience dressed
 * up — it is the SAME model Cortex uses for a preview: its own namespace, its own
 * hostname, its own resources, and nothing inherited from anywhere. So what a
 * developer learns about environments on a laptop is true on a cell.
 *
 * The default environment is the empty one, and it deliberately has no name in
 * any address it produces. A machine where the ordinary case reads
 * `main.demo.cbox.test` has made every developer pay, every day, for a feature
 * most of them use occasionally.
 */
readonly class Environment
{
    public string $name;

    /**
     * BRANCH NAMES ARE NOT DNS LABELS, and the cleaning happens HERE rather than
     * in a named constructor so there is no way into this object that skips it.
     * `feature/JIRA-42_thing` is an ordinary branch and none of `/`, `_` or an
     * uppercase letter can appear in a hostname, so it becomes
     * `feature-jira-42-thing`. Truncated at 40 because the result is one label
     * inside a longer name, and a label has 63 characters in total.
     */
    public function __construct(string $value = '')
    {
        $clean = strtolower(trim($value));
        $clean = (string) preg_replace('~[^a-z0-9]+~', '-', $clean);
        $clean = trim($clean, '-');

        if (strlen($clean) > 40) {
            $clean = rtrim(substr($clean, 0, 40), '-');
        }

        $this->name = $clean;
    }

    public static function default(): self
    {
        return new self;
    }

    public static function named(string $value): self
    {
        return new self($value);
    }

    public function isDefault(): bool
    {
        return $this->name === '';
    }

    /** What this environment's copy of a project is called. */
    public function qualify(string $project): string
    {
        return $this->isDefault() ? $project : $project.'-'.$this->name;
    }

    /**
     * A hostname, moved into this environment.
     *
     * The environment becomes a new label immediately after any leading
     * wildcard: `demo.cbox.test` becomes `feature-x.demo.cbox.test`, and
     * `*.demo.cbox.test` becomes `*.feature-x.demo.cbox.test`. One rule, both
     * shapes, and the project's own domain scheme is left recognisable — which
     * matters when somebody is reading a list of five environments trying to
     * find theirs.
     */
    public function hostname(string $domain): string
    {
        if ($this->isDefault()) {
            return $domain;
        }

        return str_starts_with($domain, '*.')
            ? '*.'.$this->name.'.'.substr($domain, 2)
            : $this->name.'.'.$domain;
    }
}
