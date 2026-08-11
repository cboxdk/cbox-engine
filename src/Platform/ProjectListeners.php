<?php

declare(strict_types=1);

namespace Cbox\Engine\Platform;

use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * The shared gateway, and the certificates its projects are served with.
 *
 * WHY A PROJECT NEEDS ITS OWN, when the cluster already has a wildcard: a TLS
 * wildcard matches exactly ONE label. `*.cbox.test` covers `demo.cbox.test` and
 * does not cover `api.demo.cbox.test` — not in a certificate, and not in a
 * Gateway listener's hostname either. Measured before this existed: over HTTP
 * every depth answered and over HTTPS only the first, which is the shape of a
 * problem somebody meets halfway through a day.
 *
 * ONE HTTPS LISTENER, MANY CERTIFICATES. The alternative — a listener per
 * project, each with its own hostname — needs a listener name that is a DNS
 * label for something that is not one, and cannot serve a name two levels deep
 * at all without a listener per level. A listener with no hostname takes every
 * name, the route hostnames decide what is actually served, and the certificate
 * is chosen by SNI from the list. Verified on the live cluster: four hostnames
 * at three depths, all 200, all verified against this machine's CA.
 *
 * DERIVED, NOT ACCUMULATED. The set is computed from the projects that exist
 * each time, so removing a project removes its certificate — an accumulated list
 * would keep every project this machine has ever run, and a gateway referencing
 * secrets that no longer exist stops programming altogether.
 */
class ProjectListeners
{
    /**
     * @param  array<string, list<string>>  $projects  project name => the hostnames it answers on
     * @return list<ManifestDocument>
     */
    public function manifests(array $projects): array
    {
        $manifests = [];
        $certificates = [['name' => 'cbox-wildcard-tls']];

        ksort($projects);

        foreach ($projects as $project => $hostnames) {
            $manifests[] = ManifestDocument::fromArray($this->certificate($project, $hostnames));
            $certificates[] = ['name' => $project.'-wildcard-tls'];
        }

        $manifests[] = ManifestDocument::fromArray($this->gateway($certificates));

        return $manifests;
    }

    /**
     * Everything one project is served under.
     *
     * @param  list<string>  $hostnames  what the project declared
     * @return array<string, mixed>
     */
    private function certificate(string $project, array $hostnames): array
    {
        return [
            'apiVersion' => 'cert-manager.io/v1',
            'kind' => 'Certificate',
            'metadata' => ['name' => $project.'-wildcard', 'namespace' => ClusterObjects::NAMESPACE],
            'spec' => [
                'secretName' => $project.'-wildcard-tls',
                'commonName' => '*.'.$project.'.'.ClusterObjects::DOMAIN,
                'dnsNames' => $this->names($project, $hostnames),
                'issuerRef' => [
                    'name' => ClusterObjects::CA_ISSUER,
                    'kind' => 'ClusterIssuer',
                    'group' => 'cert-manager.io',
                ],
            ],
        ];
    }

    /**
     * The names on a project's certificate.
     *
     * Its own name and one wildcard below it are always there, because those are
     * the two a developer reaches for without having declared anything. Anything
     * else the project declared is added VERBATIM, which is what makes a name
     * three labels deep work: a wildcard cannot reach it, and naming it can.
     *
     * @param  list<string>  $hostnames
     * @return list<string>
     */
    private function names(string $project, array $hostnames): array
    {
        $names = [
            '*.'.$project.'.'.ClusterObjects::DOMAIN,
            $project.'.'.ClusterObjects::DOMAIN,
        ];

        foreach ($hostnames as $hostname) {
            if (! in_array($hostname, $names, true)) {
                $names[] = $hostname;
            }
        }

        return $names;
    }

    /**
     * @param  list<array{name: string}>  $certificates
     * @return array<string, mixed>
     */
    private function gateway(array $certificates): array
    {
        return [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'Gateway',
            'metadata' => ['name' => ClusterObjects::GATEWAY, 'namespace' => ClusterObjects::NAMESPACE],
            'spec' => [
                'gatewayClassName' => ClusterObjects::GATEWAY_CLASS,
                'listeners' => [
                    [
                        'name' => 'http',
                        'protocol' => 'HTTP',
                        'port' => 80,
                        'allowedRoutes' => ['namespaces' => ['from' => 'All']],
                    ],
                    [
                        // No hostname: this listener takes every name, and which
                        // ones are actually served is the routes' business. A
                        // hostname here would be a second place to keep in step
                        // with them, and the narrower of the two would win
                        // silently.
                        'name' => 'https',
                        'protocol' => 'HTTPS',
                        'port' => 443,
                        'allowedRoutes' => ['namespaces' => ['from' => 'All']],
                        'tls' => [
                            'mode' => 'Terminate',
                            'certificateRefs' => $certificates,
                        ],
                    ],
                ],
            ],
        ];
    }
}
