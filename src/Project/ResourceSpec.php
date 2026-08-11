<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Platform\Database\DatabaseEngine;
use RuntimeException;

/**
 * A database or cache a project asks for, and how its details reach the
 * application.
 *
 * A RESOURCE IS CREATED AND THEN BOUND, never injected. The distinction is what
 * makes "shared or scoped per project" one mechanism instead of two: a shared
 * database is two projects binding the same resource, a scoped one is a project
 * binding its own, and nothing about the application changes between them.
 *
 * THE PASSWORD IS NEVER CARRIED. Both engines keep it in a Secret in the
 * cluster — one this platform compiles, one CloudNativePG generates — so a
 * binding resolves to a `secretKeyRef` at that Secret rather than to a copied
 * value. Nothing outside the cluster holds it, and a rotated password reaches
 * the workload on the pod's next start.
 */
readonly class ResourceSpec
{
    /**
     * @param  array<string, string>  $map  connection field => environment variable
     */
    public function __construct(
        public string $name,
        public DatabaseEngine $engine,
        public string $version,
        public string $storage,
        public array $map,
        /** Which project's namespace it lives in. Empty means this one. */
        public string $owner = '',
    ) {}

    /**
     * The engines a manifest may name, and what each one is called there.
     *
     * `postgres` and `mysql` rather than the enum's own spelling: a developer
     * writes what they run, and `percona` is the distribution rather than the
     * database as far as their application is concerned.
     */
    public static function engineFrom(string $named): DatabaseEngine
    {
        return match (strtolower(trim($named))) {
            'postgres', 'postgresql' => DatabaseEngine::Postgres,
            'mysql', 'mariadb', 'percona' => DatabaseEngine::Percona,
            'valkey', 'redis' => DatabaseEngine::Valkey,
            default => throw new RuntimeException(
                "[{$named}] is not an engine this platform runs. Use postgres, mysql or valkey."
            ),
        };
    }

    /**
     * What an application is given when it does not say.
     *
     * Named for what a framework already looks for, so a Laravel or Symfony
     * application finds its database without being told twice. A project that
     * wants different names says so and these are not used.
     *
     * @return array<string, string>
     */
    public static function defaultMap(DatabaseEngine $engine, string $name): array
    {
        $prefix = strtoupper(str_replace('-', '_', $name));

        // No password for Valkey: the platform deploys it without one, and an
        // application told to send AUTH to a server that has none fails on
        // connect. See ConnectionSourceFactory.
        return $engine === DatabaseEngine::Valkey
            ? [
                'host' => $prefix.'_HOST',
                'port' => $prefix.'_PORT',
            ]
            : [
                'host' => $prefix.'_HOST',
                'port' => $prefix.'_PORT',
                'database' => $prefix.'_DATABASE',
                'user' => $prefix.'_USERNAME',
                'password' => $prefix.'_PASSWORD',
            ];
    }
}
