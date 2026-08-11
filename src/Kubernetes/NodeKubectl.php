<?php

declare(strict_types=1);

namespace Cbox\Engine\Kubernetes;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\Kind\KindCluster;
use Cbox\Engine\ValueObjects\ApplyOutcome;
use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * Applies through the kubectl that already lives inside the cluster's node.
 *
 * NOT THE HOST'S kubectl, and that is deliberate. A developer tool whose first
 * instruction is "install kubectl" has added a step before it can help anybody,
 * and it would then depend on whatever version and whatever kubeconfig context
 * that machine happens to have — including, on this machine, a context pointed
 * at a production cell. The node ships kubectl and an admin kubeconfig, both
 * matched to the API server they talk to, and nothing outside the cluster can
 * be aimed at the wrong one by accident.
 *
 * CRDs FIRST, ALWAYS, and then a wait. A set containing both a definition and an
 * object of that kind is ordinary — every addon chart ships one — and applied in
 * file order the object arrives before the API server serves its kind. The error
 * is `no matches for kind`, which reads like a typo rather than a race.
 */
class NodeKubectl implements Kubernetes
{
    private const KUBECONFIG = '/etc/kubernetes/admin.conf';

    /**
     * One identity for everything this tool writes, matching the platform
     * compiler's. Field ownership is recorded under it, so changing it would
     * orphan every field already applied.
     */
    public const FIELD_MANAGER = 'cbox-platform';

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly int $establishAttempts = 30,
        private readonly int $establishDelay = 1_000_000,
        /** How many times to wait out a webhook that is still starting. */
        private readonly int $admissionAttempts = 60,
        private readonly int $admissionDelay = 2_000_000,
    ) {}

    /**
     * Whether this failure was the cluster not being ready to admit the object,
     * rather than anything about the object.
     *
     * A webhook that REJECTS says what is wrong with the manifest and means it.
     * A webhook that cannot be reached has not looked at it.
     */
    private function webhookUnreachable(string $error): bool
    {
        if (! str_contains($error, 'failed calling webhook')) {
            return false;
        }

        foreach (['connection refused', 'no endpoints available', 'context deadline exceeded', 'EOF'] as $reason) {
            if (str_contains($error, $reason)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ManifestDocument>  $manifests
     */
    public function apply(array $manifests, bool $dryRun = false): ApplyOutcome
    {
        if ($manifests === []) {
            return new ApplyOutcome(succeeded: true, applied: 0, output: '');
        }

        // NAMESPACES FIRST, then definitions, then everything else — and the
        // order is a property of the objects rather than of whoever assembled
        // the list. It was file order once, and a Secret placed in front of the
        // Namespace it belongs to failed the whole apply with
        // `namespaces "cbox-demo" not found`. A caller should not have to know
        // which end of the list is safe.
        $namespaces = array_values(array_filter(
            $manifests,
            static fn (ManifestDocument $m): bool => $m->kind() === 'Namespace',
        ));

        $definitions = array_values(array_filter(
            $manifests,
            static fn (ManifestDocument $m): bool => $m->kind() === 'CustomResourceDefinition',
        ));

        $rest = array_values(array_filter(
            $manifests,
            static fn (ManifestDocument $m): bool => ! in_array(
                $m->kind(),
                ['Namespace', 'CustomResourceDefinition'],
                true,
            ),
        ));

        if ($namespaces !== []) {
            $outcome = $this->send($namespaces, $dryRun);

            if (! $outcome->succeeded) {
                return $outcome;
            }
        }

        if ($definitions !== []) {
            $outcome = $this->send($definitions, $dryRun);

            if (! $outcome->succeeded) {
                return $outcome;
            }

            // A dry run establishes nothing, so there is nothing to wait for —
            // and waiting would block on kinds that will never appear.
            if (! $dryRun) {
                $this->waitForEstablished($definitions);
            }
        }

        if ($rest === []) {
            return new ApplyOutcome(
                succeeded: true,
                applied: count($namespaces) + count($definitions),
                output: '',
            );
        }

        $outcome = $this->send($rest, $dryRun);

        return new ApplyOutcome(
            succeeded: $outcome->succeeded,
            applied: $outcome->succeeded ? count($manifests) : 0,
            output: $outcome->output,
            failure: $outcome->failure,
        );
    }

    /**
     * @param  list<string>  $namespaces
     */
    public function waitForWorkloads(array $namespaces, int $seconds = 180): bool
    {
        foreach ($namespaces as $namespace) {
            // `--for=condition=Available` on Deployments is the closest thing to
            // "this addon is working": it means the replicas the controller
            // wanted are running and ready, which for a webhook is exactly the
            // question being asked.
            //
            // `--all` with nothing to wait for is an ERROR in kubectl, not a
            // success, so a namespace whose deployments have not been created
            // yet has to be tolerated — that is why this retries rather than
            // taking the first answer.
            $deadline = max(1, $seconds);

            for ($waited = 0; $waited < $deadline; $waited += 10) {
                $result = $this->runner->run($this->kubectl([
                    'wait', '--for=condition=Available', '--timeout=10s',
                    'deployment', '--all', '-n', $namespace,
                ]), timeout: 30);

                if ($result->successful()) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    public function logs(
        string $namespace,
        string $selector,
        callable $onOutput,
        bool $follow = false,
        int $tail = 100,
    ): bool {
        $arguments = [
            'logs', '-n', $namespace, '-l', $selector,
            '--tail='.$tail,
            // EVERY pod the selector matches, not the first. A service with
            // three replicas whose logs came from one of them is a log that is
            // wrong two thirds of the time, and silently.
            '--max-log-requests=20',
            // Which pod said what. Without it, three replicas interleave into
            // something that reads like one very confused process.
            '--prefix',
            '--timestamps',
        ];

        if ($follow) {
            $arguments[] = '--follow';
        }

        return $this->runner->stream(
            $this->kubectl($arguments),
            $onOutput,
            // No bound while following. Everything else here has one because a
            // tool that hangs looks like a broken machine; a follow is the case
            // where hanging IS the feature.
            $follow ? null : 60,
        )->ran;
    }

    public function delete(string $kind, string $name, string $namespace): bool
    {
        $result = $this->runner->run($this->kubectl([
            'delete', $kind, $name, '-n', $namespace, '--ignore-not-found', '--wait=true',
        ]), timeout: 120);

        return $result->successful();
    }

    /**
     * @return list<ManifestDocument>
     */
    public function list(string $kind, string $selector, string $namespace = ''): array
    {
        $arguments = ['get', $kind, '-l', $selector, '-o', 'json'];
        $arguments = $namespace === ''
            ? [...$arguments, '--all-namespaces']
            : [...$arguments, '-n', $namespace];

        $result = $this->runner->run($this->kubectl($arguments), timeout: 60);

        if (! $result->successful()) {
            return [];
        }

        $decoded = json_decode($result->text());
        $items = is_object($decoded) ? ($decoded->items ?? null) : null;

        if (! is_array($items)) {
            return [];
        }

        return ManifestDocument::listFromJson((string) json_encode($items));
    }

    public function read(string $kind, string $name, string $namespace): ?ManifestDocument
    {
        // Cluster-scoped objects take no namespace, and passing an empty one
        // is an error rather than a default.
        $arguments = $namespace === ''
            ? ['get', $kind, $name, '-o', 'json']
            : ['get', $kind, $name, '-n', $namespace, '-o', 'json'];

        $result = $this->runner->run($this->kubectl($arguments), timeout: 30);

        if (! $result->successful()) {
            return null;
        }

        $documents = ManifestDocument::listFromJson('['.$result->text().']');

        return $documents[0] ?? null;
    }

    public function serves(string $apiVersion, string $kind): bool
    {
        $result = $this->runner->run($this->kubectl(['api-resources', '--api-group='.$this->group($apiVersion), '-o', 'name']), timeout: 30);

        if (! $result->successful()) {
            return false;
        }

        foreach (preg_split('/\R/', $result->text()) ?: [] as $line) {
            if (str_starts_with(strtolower($line), strtolower($kind).'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ManifestDocument>  $manifests
     */
    private function send(array $manifests, bool $dryRun): ApplyOutcome
    {
        $command = $this->kubectl([
            'apply',
            '--server-side',
            '--field-manager='.self::FIELD_MANAGER,
            // Upstream charts and this tool both write some of the same fields;
            // without this an apply fails on a conflict rather than taking
            // ownership of what it is responsible for.
            '--force-conflicts',
            '-f', '-',
        ]);

        if ($dryRun) {
            $command[] = '--dry-run=server';
        }

        // A List, because kubectl reads one document and a JSON array is not
        // one. Everything else about the objects is untouched.
        $document = (string) json_encode([
            'apiVersion' => 'v1',
            'kind' => 'List',
            'items' => array_map(static fn (ManifestDocument $m): object => $m->body, $manifests),
        ]);

        $result = $this->runner->run($command, timeout: 300, input: $document);

        // RETRIED WHEN AN ADMISSION WEBHOOK IS NOT ANSWERING YET, and only then.
        //
        // Measured after a cluster restart: cert-manager's Deployment reported
        // Available — the replica count was satisfied from a status that had not
        // caught up — while its webhook pod was not listening, and every object
        // needing it was refused with `connection refused`. Waiting on the
        // Deployment is waiting on the wrong thing; the apply itself is the only
        // honest probe of whether the cluster will admit the object.
        //
        // Safe to retry because server-side apply is idempotent, and narrow on
        // purpose: a webhook that REJECTS an object says so immediately and is
        // not retried, because trying again would only produce the same refusal
        // more slowly.
        for ($attempt = 0; ! $result->successful() && $this->webhookUnreachable($result->errorOutput)
            && $attempt < $this->admissionAttempts; $attempt++) {
            if ($this->admissionDelay > 0) {
                usleep($this->admissionDelay);
            }

            $result = $this->runner->run($command, timeout: 300, input: $document);
        }

        if (! $result->successful()) {
            return new ApplyOutcome(
                succeeded: false,
                applied: 0,
                output: $result->text(),
                // The API server's own words: a webhook, an immutable field or a
                // missing kind says something specific, and "apply failed" would
                // throw away the only useful part.
                failure: trim($result->errorOutput) ?: $result->failure,
            );
        }

        return new ApplyOutcome(succeeded: true, applied: count($manifests), output: $result->text());
    }

    /**
     * Wait until the API server actually serves the kinds just defined.
     *
     * `kubectl apply` returns when a CustomResourceDefinition is STORED, not
     * when it is established — the API server has to accept it, run its own
     * validation and start serving the endpoint, and objects of that kind are
     * rejected with `no matches for kind` until it does.
     *
     * @param  list<ManifestDocument>  $definitions
     */
    private function waitForEstablished(array $definitions): void
    {
        $names = [];

        foreach ($definitions as $definition) {
            if ($definition->name() !== '') {
                $names[] = $definition->name();
            }
        }

        if ($names === []) {
            return;
        }

        for ($attempt = 0; $attempt < $this->establishAttempts; $attempt++) {
            $established = $this->runner->run($this->kubectl(array_merge(
                ['wait', '--for=condition=Established', '--timeout=10s', 'crd'],
                $names,
            )), timeout: 60);

            if ($established->successful()) {
                return;
            }

            if ($this->establishDelay > 0) {
                usleep($this->establishDelay);
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    public function exec(
        string $namespace,
        string $selector,
        array $command,
        bool $tty = true,
        ?callable $onOutput = null,
    ): int {
        $pod = $this->firstPod($namespace, $selector);

        if ($pod === null) {
            return -1;
        }

        // `-i` always, `-t` only with a terminal to give it: `kubectl exec -t`
        // without one produces output full of carriage returns and a program
        // that thinks it can ask questions.
        $flags = ['exec', '-n', $namespace, $pod, $tty ? '-it' : '-i', '--'];

        $arguments = [
            'docker', 'exec', $tty ? '-it' : '-i', KindCluster::NAME.'-control-plane',
            'kubectl', '--kubeconfig', self::KUBECONFIG,
            ...$flags,
            ...$command,
        ];

        // A terminal writes straight to itself; without one the output has to
        // be handed over as it arrives, or a `composer install` is ten silent
        // minutes followed by an exit code.
        $result = $tty
            ? $this->runner->interactive($arguments)
            : $this->runner->stream($arguments, $onOutput ?? static fn (string $chunk): null => null, timeout: null);

        return $result->ran ? $result->exitCode : -1;
    }

    /**
     * The name of one running pod matching the selector.
     *
     * BY NAME, because `kubectl exec -l` does not exist — exec takes one pod.
     * Running, because a pod that is terminating or pending will refuse the
     * connection with a message about the container not being ready, which
     * reads like the command was wrong.
     */
    private function firstPod(string $namespace, string $selector): ?string
    {
        foreach ($this->list('pod', $selector, $namespace) as $pod) {
            if ($pod->stringAt('status', 'phase') === 'Running' && $pod->stringAt('metadata', 'deletionTimestamp') === '') {
                return $pod->name();
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function kubectl(array $arguments): array
    {
        return array_merge(
            ['docker', 'exec', '-i', KindCluster::NAME.'-control-plane', 'kubectl', '--kubeconfig', self::KUBECONFIG],
            $arguments,
        );
    }

    private function group(string $apiVersion): string
    {
        $slash = strpos($apiVersion, '/');

        return $slash === false ? '' : substr($apiVersion, 0, $slash);
    }
}
