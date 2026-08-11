<?php

declare(strict_types=1);

namespace Cbox\Engine\Support;

use Illuminate\Support\Env as Environment;
use RuntimeException;

/**
 * Environment variables read as the type they are meant to be.
 *
 * `env()` returns mixed, because a `.env` file has no types: `APP_URL=true`
 * yields a boolean, and `APP_URL=` yields an empty string that reads as absent.
 * Everything downstream then either casts — which turns a wrong value into a
 * plausible one — or the analyser objects, and it is right to.
 *
 * REFUSES RATHER THAN COERCES. A machine configured wrongly should say so at the
 * moment it is read, naming the variable, rather than producing a URL of `1` and
 * a failure somewhere with no path back to the cause. This is a developer tool;
 * the person who can fix it is the person running it.
 *
 * FOR CONFIG FILES ONLY, and that is not a style preference. Environment
 * variables are readable while `config/` is being built and NOT afterwards: once
 * the config is cached, the process has no `.env` at all and every read returns
 * the default. Calling this from application code would therefore work in
 * development and quietly return defaults in a packaged build, which is the
 * worst available failure. Read `config()` there.
 *
 * Reads through `Illuminate\Support\Env` rather than the `env()` helper because
 * that is what the helper itself calls, and the indirection is what the rule
 * above exists to catch.
 */
class Env
{
    public static function string(string $key, string $default): string
    {
        $value = Environment::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                "[{$key}] must be text, and this machine has it set to something else ("
                .get_debug_type($value).'). Quote the value or remove it to take the default.',
            );
        }

        return $value;
    }
}
