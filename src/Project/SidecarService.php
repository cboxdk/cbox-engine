<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use RuntimeException;

/**
 * A service a project needs that this platform does not model.
 *
 * ClickHouse, Kafka, Mailpit, a VNC display. One compose file here has seven
 * of them, and none is a database in the sense `resources:` means — the
 * platform does not schedule them, back them up, or know a thing about them.
 *
 * SO THEY ARE NOT PRETENDED TO BE RESOURCES. A `resources:` entry gets a
 * managed engine with a volume, a backup policy and credentials the platform
 * owns; a sidecar gets a Deployment, a Service, and whatever the developer
 * wrote. Conflating the two would mean either lying about what the platform
 * manages or refusing to run half the applications that exist.
 *
 * LOCAL ONLY, and deliberately: `cbox push` does not send them. What a
 * production ClickHouse should be — its size, its storage, who backs it up — is
 * not a decision a development manifest gets to make by being pushed.
 */
readonly class SidecarService
{
    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $command
     */
    public function __construct(
        public string $name,
        public string $image,
        public int $port,
        public array $env = [],
        public array $command = [],
    ) {}

    /**
     * @return list<self>
     */
    public static function listFrom(mixed $parsed): array
    {
        if ($parsed === null) {
            return [];
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('`services` is a mapping of name to what to run.');
        }

        $services = [];

        foreach ($parsed as $name => $definition) {
            if (! is_string($name) || preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?$~', $name) !== 1) {
                throw new RuntimeException(
                    'A service name becomes a hostname inside the cluster, so it has to be a DNS label: '
                    .'lowercase letters, digits and dashes.',
                );
            }

            // `name: image` — the short form, because most of them are exactly
            // that and a mapping of one key is noise.
            if (is_string($definition)) {
                $services[] = new self($name, $definition, 0);

                continue;
            }

            if (! is_array($definition)) {
                throw new RuntimeException("[{$name}] is an image, or a mapping with `image` and `port`.");
            }

            $image = $definition['image'] ?? null;

            if (! is_string($image) || trim($image) === '') {
                throw new RuntimeException("[{$name}] needs an `image` — there is nothing else to run.");
            }

            $env = [];

            foreach (is_array($definition['env'] ?? null) ? $definition['env'] : [] as $key => $value) {
                if (! is_string($key) || ! is_scalar($value)) {
                    throw new RuntimeException("Every entry under [{$name}].env is a name and a plain value.");
                }

                $env[$key] = (string) $value;
            }

            $command = [];

            foreach (is_array($definition['command'] ?? null) ? $definition['command'] : [] as $part) {
                if (! is_string($part)) {
                    throw new RuntimeException("[{$name}].command is a list of arguments, one string each.");
                }

                $command[] = $part;
            }

            $port = $definition['port'] ?? 0;

            $services[] = new self(
                $name,
                trim($image),
                is_int($port) ? $port : 0,
                $env,
                $command,
            );
        }

        return $services;
    }
}
