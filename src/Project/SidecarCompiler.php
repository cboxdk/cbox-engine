<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * A sidecar service, as Kubernetes objects.
 *
 * COMPILED HERE AND NOT IN THE SHARED PACKAGE, which is the whole judgement in
 * this file. `cboxdk/platform` compiles what the PLATFORM manages — services it
 * scales, databases it backs up, gateways it programs. A ClickHouse somebody
 * needs on a laptop this afternoon is none of those, and putting it in the
 * package would mean a hosted cell could compile an arbitrary image with
 * arbitrary environment into a tenant's namespace because a manifest asked.
 *
 * So it stays local, it stays small, and it is honest about being a development
 * convenience: one Deployment, one Service, no volume, no backup, no scaling,
 * no promises.
 *
 * NO PERSISTENCE, and that is a decision rather than an omission. A sidecar's
 * data does not survive a restart, because the moment it does somebody has real
 * data in a container nothing backs up and no volume anybody named. What is
 * worth keeping goes in `resources:`, where the platform owns it.
 */
class SidecarCompiler
{
    /**
     * @param  list<SidecarService>  $services
     * @return list<ManifestDocument>
     */
    public function compile(array $services, string $namespace, string $project): array
    {
        $documents = [];

        foreach ($services as $service) {
            $documents[] = ManifestDocument::fromArray($this->deployment($service, $namespace, $project));

            if ($service->port > 0) {
                $documents[] = ManifestDocument::fromArray($this->service($service, $namespace, $project));
            }
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    private function deployment(SidecarService $service, string $namespace, string $project): array
    {
        $container = [
            'name' => $service->name,
            'image' => $service->image,
            'env' => array_map(
                static fn (string $value, string $name): array => ['name' => $name, 'value' => $value],
                $service->env,
                array_keys($service->env),
            ),
        ];

        if ($service->command !== []) {
            $container['command'] = $service->command;
        }

        if ($service->port > 0) {
            $container['ports'] = [['containerPort' => $service->port]];
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => $service->name,
                'namespace' => $namespace,
                'labels' => $this->labels($service, $project),
            ],
            'spec' => [
                'replicas' => 1,
                // The service's own name only. The project label is on the
                // object for a human to read; a selector carrying it could never
                // be changed, because a Deployment's selector is immutable.
                'selector' => ['matchLabels' => ['cbox.dk/sidecar' => $service->name]],
                'template' => [
                    'metadata' => ['labels' => $this->labels($service, $project)],
                    'spec' => ['containers' => [$container]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function service(SidecarService $service, string $namespace, string $project): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'name' => $service->name,
                'namespace' => $namespace,
                'labels' => $this->labels($service, $project),
            ],
            'spec' => [
                'selector' => ['cbox.dk/sidecar' => $service->name],
                'ports' => [['port' => $service->port, 'targetPort' => $service->port]],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function labels(SidecarService $service, string $project): array
    {
        return [
            'cbox.dk/sidecar' => $service->name,
            // The platform's own managed label, so `cbox status`, `cbox prune`
            // and the namespace delete all see it — and so nothing here is
            // invisible to the tool that created it.
            'platform.cbox.dk/managed' => 'true',
            'platform.cbox.dk/service' => $project,
        ];
    }
}
