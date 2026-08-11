<?php

declare(strict_types=1);

namespace Cbox\Engine\Tunnel;

use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * The objects that put a local environment on the public internet.
 *
 * WHY IT POINTS AT THE GATEWAY and not at the application: everything else on
 * this machine reaches the application through Envoy, and a tunnel that skipped
 * it would be testing a path that does not exist anywhere else. Proxy headers,
 * redirects, route matching, the client address an application sees — those are
 * the things a developer opens a tunnel to check, and they all live in the hop
 * this would have skipped.
 *
 * It is a Deployment in the PROJECT's namespace, so `cbox remove` takes it with
 * everything else. A tunnel outliving the project it exposed is a process
 * quietly holding a public address open to a 404 — and on a token or credentials
 * tunnel, to somebody else's real hostname.
 */
class CloudflareTunnel
{
    public const NAME = 'cbox-tunnel';

    public const METRICS_PORT = 2000;

    /**
     * Where the copy of this machine's CA is mounted.
     *
     * THE TUNNEL SPEAKS TLS TO THE GATEWAY, over the same listener a browser on
     * this machine uses, and verifies it against the same CA. Plain HTTP would
     * have been one flag shorter and would have told every application behind it
     * that the request arrived over `http` — so `X-Forwarded-Proto` says http, a
     * framework generates http:// URLs, and the developer debugging a redirect
     * loop is debugging the tunnel rather than their application.
     *
     * Verified rather than `--no-tls-verify`, because the CA is right there.
     */
    public const CA_PATH = '/etc/cbox/ca.crt';

    public function __construct(private readonly string $image) {}

    /**
     * @return list<ManifestDocument>
     */
    public function manifests(
        string $namespace,
        string $project,
        string $gateway,
        TunnelSpec $spec,
        bool $trustedCa = true,
    ): array {
        $manifests = [];

        if ($spec->secret !== '') {
            $manifests[] = ManifestDocument::fromArray($this->credentials($namespace, $spec));
        }

        if ($spec->mode === TunnelMode::Credentials) {
            $manifests[] = ManifestDocument::fromArray($this->configuration($namespace, $gateway, $spec, $trustedCa));
        }

        $manifests[] = ManifestDocument::fromArray($this->deployment($namespace, $project, $gateway, $spec, $trustedCa));

        return $manifests;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(string $namespace, TunnelSpec $spec): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => self::NAME,
                'namespace' => $namespace,
                'labels' => $this->labels(''),
            ],
            'type' => 'Opaque',
            // Base64 rather than stringData: a key written as stringData can
            // never be REMOVED by a later apply, so switching a tunnel from
            // credentials to a token would leave the old credentials behind.
            'data' => [
                $spec->mode === TunnelMode::Token ? 'token' : 'credentials.json' => base64_encode($spec->secret),
            ],
        ];
    }

    /**
     * The ingress rules, for the one mode that owns them.
     *
     * @return array<string, mixed>
     */
    private function configuration(string $namespace, string $gateway, TunnelSpec $spec, bool $trustedCa): array
    {
        $ingress = [];

        foreach ($spec->routes as $route) {
            $ingress[] = [
                'hostname' => $route->external,
                'service' => $this->origin($gateway, $trustedCa),
                'originRequest' => $trustedCa ? [
                    // The local name this arrives as. Without it the gateway
                    // sees a public hostname it has no route for.
                    'httpHostHeader' => $route->local,
                    // And the name the certificate is checked against, which is
                    // the same one — the gateway picks its certificate by SNI.
                    'originServerName' => $route->local,
                    'caPool' => self::CA_PATH,
                ] : ['httpHostHeader' => $route->local],
            ];
        }

        // cloudflared REQUIRES a catch-all, and refuses to start without one.
        // 404 rather than a rule that sends unmatched names to the gateway: a
        // tunnel is a public address, and anything not named here was not meant
        // to be reachable.
        $ingress[] = ['service' => 'http_status:404'];

        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => self::NAME,
                'namespace' => $namespace,
                'labels' => $this->labels(''),
            ],
            'data' => [
                'config.yaml' => $this->yaml([
                    'tunnel' => $spec->tunnelId(),
                    'credentials-file' => '/etc/cloudflared/credentials.json',
                    'no-autoupdate' => true,
                    'metrics' => '0.0.0.0:'.self::METRICS_PORT,
                    'ingress' => $ingress,
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deployment(
        string $namespace,
        string $project,
        string $gateway,
        TunnelSpec $spec,
        bool $trustedCa,
    ): array {
        $configured = $spec->mode === TunnelMode::Credentials;

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => [
                'name' => self::NAME,
                'namespace' => $namespace,
                'labels' => $this->labels($project),
            ],
            'spec' => [
                // One. Two connectors would both answer for the same hostname,
                // which works, and doubles the tunnel's connections to
                // Cloudflare for a laptop serving one developer.
                'replicas' => 1,
                // REPLACED, NEVER OVERLAPPED. A default rolling update starts
                // the new connector before stopping the old one, and for a
                // quick tunnel that means two connectors registering two
                // different public addresses — with this tool reading the
                // logs of whichever pod it happened to find and telling
                // somebody an address that stops working a moment later.
                // Measured: two pods, two trycloudflare hostnames, the stale
                // one reported.
                // `maxSurge: 0` rather than a Recreate strategy, which reads
                // more plainly and cannot be applied over a Deployment the API
                // server has already defaulted a rollingUpdate block onto:
                //     spec.strategy.rollingUpdate: Forbidden: may not be
                //     specified when strategy `type` is 'Recreate'
                'strategy' => [
                    'type' => 'RollingUpdate',
                    'rollingUpdate' => ['maxSurge' => 0, 'maxUnavailable' => 1],
                ],
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => self::NAME]],
                'template' => [
                    'metadata' => ['labels' => $this->labels($project)],
                    'spec' => [
                        'containers' => [[
                            'name' => 'cloudflared',
                            'image' => $this->image,
                            'args' => $this->arguments($gateway, $spec, $trustedCa),
                            'env' => $spec->mode === TunnelMode::Token ? [[
                                'name' => 'TUNNEL_TOKEN',
                                'valueFrom' => ['secretKeyRef' => ['name' => self::NAME, 'key' => 'token']],
                            ]] : [],
                            'ports' => [['name' => 'metrics', 'containerPort' => self::METRICS_PORT]],
                            // cloudflared's own answer to "am I connected", so
                            // a tunnel that is running but has not reached
                            // Cloudflare does not read as ready.
                            'readinessProbe' => [
                                'httpGet' => ['path' => '/ready', 'port' => self::METRICS_PORT],
                                'periodSeconds' => 5,
                            ],
                            'resources' => [
                                'requests' => ['cpu' => '10m', 'memory' => '32Mi'],
                                'limits' => ['memory' => '128Mi'],
                            ],
                            'securityContext' => [
                                'allowPrivilegeEscalation' => false,
                                'runAsNonRoot' => true,
                                'runAsUser' => 65532,
                                'capabilities' => ['drop' => ['ALL']],
                            ],
                            'volumeMounts' => [
                                ...($configured ? [
                                    ['name' => 'config', 'mountPath' => '/etc/cloudflared/config.yaml', 'subPath' => 'config.yaml'],
                                    ['name' => 'credentials', 'mountPath' => '/etc/cloudflared/credentials.json', 'subPath' => 'credentials.json'],
                                ] : []),
                                ...($trustedCa ? [
                                    ['name' => 'ca', 'mountPath' => self::CA_PATH, 'subPath' => 'ca.crt', 'readOnly' => true],
                                ] : []),
                            ],
                        ]],
                        'volumes' => [
                            ...($configured ? [
                                ['name' => 'config', 'configMap' => ['name' => self::NAME]],
                                ['name' => 'credentials', 'secret' => ['secretName' => self::NAME]],
                            ] : []),
                            ...($trustedCa ? [[
                                'name' => 'ca',
                                'secret' => [
                                    'secretName' => ClusterObjects::CA_ISSUER,
                                    // The certificate half only. The CA's PRIVATE
                                    // KEY is in the same Secret, and a connector
                                    // that talks to the internet has no business
                                    // holding the key that signs every name on
                                    // this machine.
                                    'items' => [['key' => 'tls.crt', 'path' => 'ca.crt']],
                                ],
                            ]] : []),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function arguments(string $gateway, TunnelSpec $spec, bool $trustedCa): array
    {
        $common = ['tunnel', '--no-autoupdate', '--metrics', '0.0.0.0:'.self::METRICS_PORT];

        return match ($spec->mode) {
            TunnelMode::Quick => [
                ...$common,
                '--url', $this->origin($gateway, $trustedCa),
                // The local name the request arrives at the gateway as. A quick
                // tunnel's own address is assigned by Cloudflare and matches no
                // route here.
                '--http-host-header', $spec->routes[0]->local,
                ...($trustedCa ? [
                    '--origin-server-name', $spec->routes[0]->local,
                    '--origin-ca-pool', self::CA_PATH,
                ] : []),
            ],
            // Routing lives in the dashboard for this one; the token says which
            // tunnel, and Cloudflare says where it goes.
            TunnelMode::Token => [...$common, 'run'],
            TunnelMode::Credentials => ['tunnel', '--no-autoupdate', '--config', '/etc/cloudflared/config.yaml', 'run'],
        };
    }

    /**
     * The gateway, spoken to the way a browser on this machine speaks to it.
     *
     * Plain HTTP is the fallback for a project deployed before the CA was copied
     * into its namespace — reachable, and honest about being a slightly
     * different path.
     */
    private function origin(string $gateway, bool $trustedCa): string
    {
        return $trustedCa ? 'https://'.$gateway.':443' : 'http://'.$gateway.':80';
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $project): array
    {
        $labels = [
            'app.kubernetes.io/name' => self::NAME,
            'app.kubernetes.io/managed-by' => 'cbox-platform',
        ];

        // The project label is what makes this show up as the project's own, and
        // it is deliberately absent from the pod SELECTOR: a selector is
        // immutable, and one carrying a name would have to be deleted and
        // recreated the day a project is renamed.
        return $project === '' ? $labels : [...$labels, 'platform.cbox.dk/service' => $project];
    }

    /**
     * Just enough YAML for one config file.
     *
     * Written here rather than pulled in, because the whole document is three
     * shapes — a scalar, a list of maps, and a map — and a dependency in the
     * request path of somebody's development machine should buy more than that.
     *
     * @param  array<string, mixed>  $config
     */
    private function yaml(array $config): string
    {
        $lines = [];

        foreach ($config as $key => $value) {
            if (! is_array($value)) {
                $lines[] = $key.': '.$this->scalar($value);

                continue;
            }

            $lines[] = $key.':';

            /** @var array<string, mixed> $entry */
            foreach ($value as $entry) {
                $first = true;

                foreach ($entry as $name => $inner) {
                    if (is_array($inner)) {
                        $lines[] = '  '.($first ? '- ' : '  ').$name.':';

                        /** @var mixed $deep */
                        foreach ($inner as $deepName => $deep) {
                            $lines[] = '      '.$deepName.': '.$this->scalar($deep);
                        }
                    } else {
                        $lines[] = '  '.($first ? '- ' : '  ').$name.': '.$this->scalar($inner);
                    }

                    $first = false;
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // Quoted, always. `metrics: 0.0.0.0:2000` and a hostname that happens to
        // read as a number are both values YAML would take away from us.
        return '"'.str_replace('"', '\"', (string) (is_scalar($value) ? $value : '')).'"';
    }
}
