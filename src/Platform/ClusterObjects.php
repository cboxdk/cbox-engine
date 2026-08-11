<?php

declare(strict_types=1);

namespace Cbox\Engine\Platform;

use Cbox\Engine\Kind\ClusterConfig;
use Cbox\Engine\ValueObjects\ManifestDocument;
use stdClass;

/**
 * The cluster-level objects the addons cannot ship, because no chart can know
 * what to call them.
 *
 * A GatewayClass is the clearest case: nothing upstream provides one, so a
 * cluster with Envoy Gateway installed and no class gets Gateways that sit
 * `Accepted=Unknown` forever with no controller ever claiming them. Cortex met
 * exactly that on a fresh tenant.
 *
 * These are the LOCAL equivalents of what a cell applies, deliberately named the
 * same way and shaped the same way, so a Gateway compiled by the shared platform
 * package finds what it expects on either side.
 */
class ClusterObjects
{
    /** What a compiled Gateway names. Same class name a cell uses. */
    public const GATEWAY_CLASS = 'cbox';

    /** The one gateway every project's routes attach to. */
    public const GATEWAY = 'cbox';

    /** The authority every local certificate is signed by. */
    public const CA_ISSUER = 'cbox-ca';

    /** Where the shared gateway and its certificate live. */
    public const NAMESPACE = 'cbox-system';

    /**
     * The suffix every local hostname sits under.
     *
     * `.test` is reserved by RFC 6761 for exactly this and can never be
     * delegated to anybody, so a name here cannot one day start resolving to
     * somebody else's server. `.dev` and `.app` are real, public, HSTS-preloaded
     * top-level domains and using one would be borrowing a name that is not
     * ours.
     */
    public const DOMAIN = 'cbox.test';

    private const CA_SECRET = 'cbox-ca';

    public function __construct(private readonly ?LocalAuthority $authority = null) {}

    /**
     * @return list<ManifestDocument>
     */
    public function manifests(): array
    {
        return array_map(ManifestDocument::fromArray(...), [
            ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => self::NAMESPACE]],
            $this->proxy(),
            $this->gatewayClass(),
            ...$this->certificateAuthority(),
            ...$this->wildcardCertificate(),
            ...$this->resolver(),
        ]);
    }

    /**
     * How big the proxy is allowed to be.
     *
     * Smaller than a cell's, because this one shares a laptop with the editor,
     * the browser and every other project. The chart's defaults reserve most of
     * a machine for components that use a fraction of it.
     *
     * NO PROXY PROTOCOL HERE YET, and that is a real difference from production
     * rather than an oversight. On a cell the load balancer NATs, so the client
     * address is gone before Envoy sees the connection and `ClientTrafficPolicy`
     * teaches Envoy to read it from the PROXY protocol header instead. There is
     * no load balancer in front of this one — for now. When the front proxy that
     * owns ports 80 and 443 arrives, it speaks PROXY protocol exactly as the
     * cloud load balancer does, this gains the same policy, and the whole client
     * address chain becomes identical. Turning it on before then would break
     * every request: Envoy would read a plain HTTP request as a malformed
     * preamble.
     *
     * @return array<string, mixed>
     */
    private function proxy(): array
    {
        return [
            'apiVersion' => 'gateway.envoyproxy.io/v1alpha1',
            'kind' => 'EnvoyProxy',
            'metadata' => ['name' => 'cbox-proxy', 'namespace' => 'envoy-gateway-system'],
            'spec' => [
                'provider' => [
                    'type' => 'Kubernetes',
                    'kubernetes' => [
                        'envoyDeployment' => [
                            'container' => [
                                'resources' => [
                                    'requests' => ['cpu' => '10m', 'memory' => '64Mi'],
                                    'limits' => ['memory' => '256Mi'],
                                ],
                            ],
                        ],
                        // A NodePort service, because that is what a kind
                        // cluster can publish: there is no cloud load balancer
                        // to allocate, and a LoadBalancer service would sit
                        // Pending forever with an external address that never
                        // arrives.
                        // PINNED, because kind's port mappings are fixed when
                        // the cluster is built and a randomly allocated node
                        // port lands somewhere the host cannot reach. It is also
                        // why there is ONE shared gateway: two services cannot
                        // hold the same node port.
                        'envoyService' => [
                            'type' => 'NodePort',
                            'patch' => [
                                'type' => 'StrategicMerge',
                                'value' => ['spec' => ['ports' => [
                                    ['port' => 80, 'nodePort' => ClusterConfig::HTTP_NODE_PORT],
                                    ['port' => 443, 'nodePort' => ClusterConfig::HTTPS_NODE_PORT],
                                ]]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayClass(): array
    {
        return [
            'apiVersion' => 'gateway.networking.k8s.io/v1',
            'kind' => 'GatewayClass',
            'metadata' => ['name' => self::GATEWAY_CLASS],
            'spec' => [
                'controllerName' => 'gateway.envoyproxy.io/gatewayclass-controller',
                'parametersRef' => [
                    'group' => 'gateway.envoyproxy.io',
                    'kind' => 'EnvoyProxy',
                    'name' => 'cbox-proxy',
                    'namespace' => 'envoy-gateway-system',
                ],
            ],
        ];
    }

    /**
     * A certificate authority for this machine, and certificates signed by it.
     *
     * REAL CERTIFICATES FROM A REAL CHAIN, which is the point. A public
     * authority can never validate a name that resolves to a laptop, so the
     * alternative is either no TLS locally — and then every difference between
     * http and https is untested until production — or a pile of per-site
     * self-signed certificates that nothing can be taught to trust at once.
     *
     * One authority, trusted once, signing everything after. The same shape as
     * production: a ClusterIssuer that a compiled Certificate names, so the
     * compiled objects are identical and only the issuer behind them differs.
     *
     * `selfSigned: {}` IS AN EMPTY MAP, and it is written as an object on
     * purpose. As an empty PHP array it would encode as `[]` and cert-manager
     * would refuse it — see ManifestDocument for the failure this produced in
     * the Gateway API bundle.
     *
     * @return list<array<string, mixed>>
     */
    private function certificateAuthority(): array
    {
        $issuer = [
            'apiVersion' => 'cert-manager.io/v1',
            'kind' => 'ClusterIssuer',
            'metadata' => ['name' => self::CA_ISSUER],
            'spec' => ['ca' => ['secretName' => self::CA_SECRET]],
        ];

        // AN AUTHORITY THIS MACHINE ALREADY TRUSTS, when there is one.
        //
        // Seeded as a Secret and no Certificate at all: cert-manager has nothing
        // to issue here, because the root already exists and re-issuing it is
        // precisely what must not happen. Every leaf still comes from it.
        //
        // Without this, `cbox destroy` and `cbox up` mint a new root, every
        // certificate under it is signed by something the machine has never
        // seen, and the browser goes back to a full-page warning that says
        // nothing about what changed.
        $seed = $this->authority?->seed() ?? [];

        if ($seed !== []) {
            /** @var array<string, mixed> $secret */
            $secret = json_decode((string) json_encode($seed[0]->body), true);

            return [$secret, $issuer];
        }

        return [
            [
                'apiVersion' => 'cert-manager.io/v1',
                'kind' => 'ClusterIssuer',
                'metadata' => ['name' => 'cbox-selfsigned'],
                'spec' => ['selfSigned' => new stdClass],
            ],
            [
                'apiVersion' => 'cert-manager.io/v1',
                'kind' => 'Certificate',
                'metadata' => ['name' => self::CA_SECRET, 'namespace' => 'cert-manager'],
                'spec' => [
                    'isCA' => true,
                    'commonName' => 'Cbox Local CA',
                    'secretName' => self::CA_SECRET,
                    // Long, because trusting an authority costs the developer a
                    // password prompt and doing it quarterly would be a reason
                    // to uninstall.
                    'duration' => '87600h',
                    'privateKey' => ['algorithm' => 'ECDSA', 'size' => 256],
                    'issuerRef' => [
                        'name' => 'cbox-selfsigned',
                        'kind' => 'ClusterIssuer',
                        'group' => 'cert-manager.io',
                    ],
                ],
            ],
            $issuer,
        ];
    }

    /**
     * The certificate every project's own hostname is served with.
     *
     * The GATEWAY is not here: its listeners depend on which projects exist, so
     * it is derived on each deploy by {@see ProjectListeners} rather than
     * installed once. This certificate is the part that does not — every project
     * has a name directly under the domain, whatever else it answers on.
     *
     * @return list<array<string, mixed>>
     */
    private function wildcardCertificate(): array
    {
        return [[
            'apiVersion' => 'cert-manager.io/v1',
            'kind' => 'Certificate',
            'metadata' => ['name' => 'cbox-wildcard', 'namespace' => self::NAMESPACE],
            'spec' => [
                'secretName' => 'cbox-wildcard-tls',
                'commonName' => '*.'.self::DOMAIN,
                'dnsNames' => ['*.'.self::DOMAIN, self::DOMAIN],
                'issuerRef' => [
                    'name' => self::CA_ISSUER,
                    'kind' => 'ClusterIssuer',
                    'group' => 'cert-manager.io',
                ],
            ],
        ]];
    }

    /**
     * A nameserver that answers for this machine's development domain.
     *
     * WHY THIS EXISTS AT ALL: a project's hostname has to resolve, or the only
     * way to reach it is `curl --resolve` and a browser cannot open it. The two
     * ordinary answers are both worse. An `/etc/hosts` entry per project means a
     * password prompt on every deploy. A resolver pointed at port 53 means
     * something on the machine has to bind a privileged port and stay bound.
     *
     * So: a nameserver inside the cluster, published on an unprivileged port,
     * and one five-line file in `/etc/resolver` written ONCE. macOS resolvers
     * take a `port` directive, which is what makes that possible.
     *
     * EVERY name under the domain, to one address. There is nothing to keep in
     * step — no per-project record, no reconciliation, nothing that can drift
     * from what is deployed — because the answer is the same for a project that
     * exists and one that does not. What decides whether a hostname WORKS is the
     * gateway's routing, which is the thing that actually knows.
     *
     * @return list<array<string, mixed>>
     */
    private function resolver(): array
    {
        $domain = self::DOMAIN;

        // ZONED AT THE SERVER BLOCK, which is both simpler and stricter than
        // matching a pattern inside it.
        //
        // The first attempt passed a regex where CoreDNS expects a ZONE — the
        // positional argument to `template` is a zone name, not a pattern — so
        // nothing matched and every query fell through to a plugin that was not
        // there: `plugin/template: no next plugin found`. Zoning the server
        // instead means a name outside the domain is refused rather than
        // considered, which is what a nameserver for one development domain
        // should do.
        $corefile = <<<COREFILE
            {$domain}:5353 {
                template IN A {
                    answer "{{ .Name }} 60 IN A 127.0.0.1"
                }
                # An empty answer rather than none: it is how you say "this name
                # has no IPv6" without a client waiting out a timeout to find
                # out. Same for anything else asked about a name that exists.
                template IN AAAA {
                    rcode NOERROR
                }
                template IN ANY {
                    rcode NOERROR
                }
                errors
            }
            COREFILE;

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => ['name' => 'cbox-resolver', 'namespace' => self::NAMESPACE],
                'data' => ['Corefile' => $corefile],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => ['name' => 'cbox-resolver', 'namespace' => self::NAMESPACE],
                'spec' => [
                    'replicas' => 1,
                    'selector' => ['matchLabels' => ['app' => 'cbox-resolver']],
                    'template' => [
                        'metadata' => ['labels' => ['app' => 'cbox-resolver']],
                        'spec' => [
                            'containers' => [[
                                'name' => 'coredns',
                                'image' => 'registry.k8s.io/coredns/coredns:v1.13.1',
                                'args' => ['-conf', '/etc/coredns/Corefile'],
                                'ports' => [
                                    ['containerPort' => 5353, 'protocol' => 'UDP'],
                                    ['containerPort' => 5353, 'protocol' => 'TCP'],
                                ],
                                'volumeMounts' => [[
                                    'name' => 'config',
                                    'mountPath' => '/etc/coredns',
                                    'readOnly' => true,
                                ]],
                                'resources' => ['requests' => ['cpu' => '10m', 'memory' => '32Mi']],
                            ]],
                            'volumes' => [[
                                'name' => 'config',
                                'configMap' => ['name' => 'cbox-resolver'],
                            ]],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => ['name' => 'cbox-resolver', 'namespace' => self::NAMESPACE],
                'spec' => [
                    'type' => 'NodePort',
                    'selector' => ['app' => 'cbox-resolver'],
                    'ports' => [
                        [
                            'name' => 'dns-udp',
                            'port' => 5353,
                            'targetPort' => 5353,
                            'protocol' => 'UDP',
                            'nodePort' => ClusterConfig::DNS_NODE_PORT,
                        ],
                        [
                            'name' => 'dns-tcp',
                            'port' => 5353,
                            'targetPort' => 5353,
                            'protocol' => 'TCP',
                            'nodePort' => ClusterConfig::DNS_NODE_PORT,
                        ],
                    ],
                ],
            ],
        ];
    }
}
