<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Platform\GatewayAddress;
use Cbox\Engine\Project\ProjectLocator;
use Cbox\Engine\Tunnel\CloudflareTunnel;
use Cbox\Engine\Tunnel\TunnelMode;
use Cbox\Engine\Tunnel\TunnelRoute;
use Cbox\Engine\Tunnel\TunnelSpec;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Put this project on the public internet.
 *
 * THE GAP THIS CLOSES is the one that makes local development stop halfway: a
 * phone on mobile data cannot reach a laptop, and neither can a payment
 * provider's webhook, a partner's API, or a colleague in another office. The
 * usual answers are a port forward nobody is allowed to configure, or deploying
 * to a staging environment to test one callback.
 *
 * It goes through the SAME GATEWAY as everything else here, so what the outside
 * world reaches is the environment being developed, not a different path to it.
 *
 * `cbox expose` with nothing else gets a throwaway address in a few seconds and
 * needs no Cloudflare account at all.
 */
class ExposeCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:expose
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which environment, defaulting to the worktree you are in}
                            {--hostname=* : A public hostname, optionally as public.example.com:local.cbox.test}
                            {--token= : A tunnel token from the Cloudflare dashboard}
                            {--credentials= : Path to the JSON from `cloudflared tunnel create`}
                            {--wait=90 : Seconds to wait for the tunnel to connect}
                            {--json : Machine-readable output}';

    protected $description = 'Expose this project through a Cloudflare tunnel';

    public function handle(
        ProjectLocator $locator,
        Kubernetes $kubernetes,
        GatewayAddress $gateway,
        CloudflareTunnel $tunnel,
    ): int {
        $manifest = $this->locateProject($locator);

        if ($manifest === null) {
            return self::FAILURE;
        }

        try {
            $spec = $this->spec($manifest->domains[0] ?? '');
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        $namespace = $manifest->namespace();

        if ($kubernetes->read('namespace', $namespace, '') === null) {
            $this->error('  ['.$this->label($manifest).'] is not on the cluster. Deploy it first.');

            return self::FAILURE;
        }

        $address = $gateway->internal();

        if ($address === null) {
            $this->error('  The gateway is not up, so there is nothing to expose yet.');

            return self::FAILURE;
        }

        // The CA is copied into a project's namespace when it is deployed, and
        // a project deployed before that started happening does not have it. The
        // tunnel then talks to the gateway over plain HTTP rather than refusing
        // to start — reachable, and one deploy away from the better path.
        $trustedCa = $kubernetes->read('secret', ClusterObjects::CA_ISSUER, $namespace) !== null;

        $outcome = $kubernetes->apply(
            $tunnel->manifests($namespace, $manifest->deployedName(), $address, $spec, $trustedCa),
        );

        if (! $outcome->succeeded) {
            $this->error('  The tunnel could not be started.');
            $this->line("      {$outcome->failure}");

            return self::FAILURE;
        }

        $url = $this->publicAddress($kubernetes, $namespace, $spec);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'name' => $manifest->deployedName(),
                'environment' => $manifest->environment->name,
                'namespace' => $namespace,
                'mode' => $spec->mode->value,
                'tls' => $trustedCa,
                'url' => $url,
                'routes' => array_map(
                    static fn (TunnelRoute $route): array => ['public' => $route->external, 'local' => $route->local],
                    $spec->routes,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $url === null && $spec->mode === TunnelMode::Quick ? self::FAILURE : self::SUCCESS;
        }

        return $this->report($this->label($manifest), $spec, $url, $address, $trustedCa);
    }

    /**
     * Which kind of tunnel this is, from what the person gave it.
     *
     * The mode is DERIVED rather than asked for, because the credentials already
     * say which one it is and a `--mode` that could disagree with them is a way
     * to be told the wrong thing.
     */
    private function spec(string $defaultLocal): TunnelSpec
    {
        $hostnames = array_values(array_filter(
            is_array($this->option('hostname')) ? $this->option('hostname') : [],
            is_string(...),
        ));

        $token = is_string($this->option('token')) ? trim($this->option('token')) : '';
        $credentials = is_string($this->option('credentials')) ? trim($this->option('credentials')) : '';

        if ($token !== '' && $credentials !== '') {
            throw new RuntimeException('A tunnel is authorised by a token or by credentials, not both.');
        }

        $routes = array_map(
            static fn (string $hostname): TunnelRoute => TunnelRoute::parse($hostname, $defaultLocal),
            $hostnames,
        );

        if ($credentials !== '') {
            return new TunnelSpec(TunnelMode::Credentials, $routes, $this->read($credentials));
        }

        if ($token !== '') {
            return new TunnelSpec(TunnelMode::Token, $routes, $token);
        }

        if ($routes === []) {
            if ($defaultLocal === '') {
                throw new RuntimeException(
                    'This project declares no domains, so there is nothing for a quick tunnel to carry. '
                    .'Name one with --hostname=public.example.com:local.cbox.test.',
                );
            }

            $routes = [new TunnelRoute('', $defaultLocal)];
        }

        return new TunnelSpec(TunnelMode::Quick, $routes);
    }

    private function read(string $path): string
    {
        $expanded = str_starts_with($path, '~/')
            ? (getenv('HOME') ?: '').substr($path, 1)
            : $path;

        $contents = is_file($expanded) ? file_get_contents($expanded) : false;

        if ($contents === false) {
            throw new RuntimeException("There is no credentials file at [{$path}].");
        }

        return $contents;
    }

    /**
     * The address the outside world will use.
     *
     * ONLY A QUICK TUNNEL HAS TO BE ASKED. Cloudflare picks its name and says so
     * once, in the connector's log, and a developer who has to go and find that
     * themselves has been handed a job rather than an address. The other two
     * modes were told their hostnames by the person running this.
     */
    private function publicAddress(Kubernetes $kubernetes, string $namespace, TunnelSpec $spec): ?string
    {
        if ($spec->mode !== TunnelMode::Quick) {
            return $spec->routes[0]->external === '' ? null : 'https://'.$spec->routes[0]->external;
        }

        $seconds = max(5, (int) $this->option('wait'));
        $deadline = time() + $seconds;
        $found = null;

        // ONE CONNECTOR BEFORE ANY LOG IS READ. Even with a Recreate strategy
        // there is a moment where the previous pod is still terminating, and its
        // log still holds the address it was given — which is the address this
        // would report, and it would already be dead.
        $this->awaitOneConnector($kubernetes, $namespace, $deadline);

        while (time() < $deadline && $found === null) {
            $kubernetes->logs(
                $namespace,
                'app.kubernetes.io/name='.CloudflareTunnel::NAME,
                function (string $chunk) use (&$found): void {
                    if ($found === null && preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~', $chunk, $matches) === 1) {
                        $found = $matches[0];
                    }
                },
                follow: false,
                tail: 200,
            );

            if ($found === null) {
                sleep(2);
            }
        }

        return $found;
    }

    /**
     * Wait until exactly one tunnel pod is running and none is on its way out.
     */
    private function awaitOneConnector(Kubernetes $kubernetes, string $namespace, int $deadline): void
    {
        while (time() < $deadline) {
            $pods = $kubernetes->list('pod', 'app.kubernetes.io/name='.CloudflareTunnel::NAME, $namespace);

            $live = array_filter($pods, static fn ($pod): bool => $pod->stringAt('metadata', 'deletionTimestamp') === ''
                && $pod->stringAt('status', 'phase') === 'Running');

            if (count($pods) === 1 && count($live) === 1) {
                return;
            }

            sleep(1);
        }
    }

    private function report(string $project, TunnelSpec $spec, ?string $url, string $gateway, bool $trustedCa): int
    {
        if (! $trustedCa) {
            // Worth saying, because it is the difference between an application
            // seeing `X-Forwarded-Proto: https` and seeing `http` — which is
            // where redirect loops and http:// asset URLs come from.
            $this->line('  This project has no copy of the local CA, so the tunnel reaches the gateway over plain HTTP.');
            $this->line('      Deploy it again to fix that.');
        }

        if ($spec->mode === TunnelMode::Quick) {
            if ($url === null) {
                $this->error('  The tunnel started but has not been given an address yet.');
                $this->line('      cbox logs --process=cbox-tunnel will say why. Usually it is a network that blocks outbound QUIC.');

                return self::FAILURE;
            }

            $this->line("  <fg=green>✓</> [{$project}] is reachable at {$url}");
            $this->line("      Arriving as {$spec->routes[0]->local}. The address is temporary and changes each time.");

            return self::SUCCESS;
        }

        $this->line("  <fg=green>✓</> [{$project}] tunnel running.");

        foreach ($spec->routes as $route) {
            $this->line("      https://{$route->external} → {$route->local}");
        }

        if ($spec->mode === TunnelMode::Token) {
            // Its routing lives in Cloudflare, so this is the one thing this
            // command knows and the dashboard needs.
            $this->line("      Point the tunnel's public hostname at <options=bold>http://{$gateway}</> in the Cloudflare dashboard,");
            $this->line('      with the origin request Host header set to the local hostname above.');
        }

        return self::SUCCESS;
    }
}
