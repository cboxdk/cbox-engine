<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\Kubernetes;

/**
 * The root password of an engine this platform schedules itself.
 *
 * GENERATED ONCE, THEN READ BACK. The Secret in the cluster is the store —
 * there is nowhere else it could live. A manifest is committed to a repository
 * and must never hold it; a file beside the manifest is a file somebody commits
 * by accident; and generating a new one on every deploy would hand the database
 * a password its own data directory was not initialised with, which locks the
 * application out of a database that is running perfectly.
 *
 * ONLY WHERE THE ENGINE NEEDS ONE. Valkey takes no password on this platform,
 * and inventing one for it would put a Secret in front of a container that
 * never reads it.
 */
class DatabasePasswords
{
    public function __construct(private readonly Kubernetes $kubernetes) {}

    /**
     * @param  list<ResourceSpec>  $resources
     * @return array<string, string> resource name => password
     */
    public function forResources(array $resources, string $namespace): array
    {
        $passwords = [];

        foreach ($resources as $resource) {
            if (! $resource->engine->needsPassword()) {
                continue;
            }

            $passwords[$resource->name] = $this->existing($resource->name, $namespace)
                ?? $this->generate();
        }

        return $passwords;
    }

    /** What the cluster already holds, if this database has been deployed before. */
    private function existing(string $name, string $namespace): ?string
    {
        $secret = $this->kubernetes->read('secret', $name.'-credentials', $namespace);

        if ($secret === null) {
            return null;
        }

        $data = $secret->body->data ?? null;
        $encoded = is_object($data) ? ($data->password ?? null) : null;

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * A password nobody chose.
     *
     * `random_bytes`, not `rand`: this is a credential, and the difference
     * between a cryptographic source and a convenient one is the whole of it
     * even on a laptop — a development database is where a developer's real
     * data ends up.
     *
     * Hex rather than base64: it survives a shell, a URL and a MySQL connection
     * string without anything having to think about escaping.
     */
    private function generate(): string
    {
        return bin2hex(random_bytes(24));
    }
}
