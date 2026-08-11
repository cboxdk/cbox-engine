<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Database\DatabaseEngine;

/**
 * Where a database's connection details actually live in the cluster.
 *
 * THESE NAMES ARE NOT OURS TO CHOOSE. CloudNativePG generates `<cluster>-app`
 * holding the application user's credentials and publishes a read-write Service
 * at `<cluster>-rw`; the engines this platform schedules itself keep a
 * `<name>-credentials` Secret with a single `password` key. Both are contracts
 * of the thing that created them, and getting one wrong produces an application
 * that starts perfectly and cannot connect.
 *
 * A NEAR-COPY OF CORTEX'S ConnectionSourceMapper, and that is a known
 * duplication rather than an oversight. It is pure — a name, a namespace and an
 * engine in, a value object out — so it belongs in `cboxdk/platform` where both
 * consumers would read one copy. Left here until the second use exists to
 * extract it against, which is the same rule that produced the package in the
 * first place; the moment it drifts, it drifts into an application that cannot
 * reach its database.
 */
class ConnectionSourceFactory
{
    public function forResource(ResourceSpec $resource, string $namespace): ConnectionSource
    {
        if ($resource->engine === DatabaseEngine::Postgres) {
            return new ConnectionSource(
                secretName: "{$resource->name}-app",
                secretKeys: [
                    ConnectionField::User->value => 'username',
                    ConnectionField::Password->value => 'password',
                ],
                plain: [
                    ConnectionField::Host->value => "{$resource->name}-rw.{$namespace}.svc.cluster.local",
                    ConnectionField::Port->value => '5432',
                    ConnectionField::Database->value => 'app',
                ],
            );
        }

        $valkey = $resource->engine === DatabaseEngine::Valkey;

        // VALKEY HAS NO SECRET TO POINT AT, and saying it does produced an
        // application that could not start at all.
        //
        // The compiler emits `<name>-credentials` only for Percona — Valkey's
        // StatefulSet never references it and `DatabaseEngine::needsPassword()`
        // says so. Binding a Valkey password anyway made every workload mount a
        // Secret nothing creates: `CreateContainerConfigError`, forever, on a
        // cache that was itself running perfectly.
        //
        // Measured on a local cluster. Cortex's mapper has the same shape and
        // therefore the same latent defect, on any service that binds a Valkey.
        //
        // Worth saying plainly: this means the local Valkey takes no password,
        // because that is what the platform actually deploys. Giving it one is a
        // change to the compiler's container arguments and to every cache
        // already running, so it is a decision rather than a patch.
        return new ConnectionSource(
            secretName: $valkey ? '' : "{$resource->name}-credentials",
            secretKeys: $valkey ? [] : [ConnectionField::Password->value => 'password'],
            plain: [
                ConnectionField::Host->value => "{$resource->name}.{$namespace}.svc.cluster.local",
                ConnectionField::Port->value => $valkey ? '6379' : '3306',
                ConnectionField::Database->value => $valkey ? '0' : 'app',
                ConnectionField::User->value => $valkey ? 'default' : 'root',
            ],
        );
    }
}
