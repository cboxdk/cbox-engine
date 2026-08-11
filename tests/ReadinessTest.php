<?php

declare(strict_types=1);

use Cbox\Engine\Kubernetes\NodeKubectl;
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\Testing\FakeHttpProbe;
use Cbox\Engine\ValueObjects\CommandResult;
use Cbox\Engine\ValueObjects\ManifestDocument;

/*
 * A restarted cluster lies about being ready, and it lies through every
 * structural signal there is. These pin the two places that cost.
 */

/**
 * @return list<ManifestDocument>
 */
function oneObject(): array
{
    return ManifestDocument::listFromJson('[{"apiVersion":"v1","kind":"ConfigMap","metadata":{"name":"x"}}]');
}

/**
 * @return list<string>
 */
function applyCommand(): array
{
    return ['docker', 'exec', '-i', 'cbox-control-plane', 'kubectl', '--kubeconfig',
        '/etc/kubernetes/admin.conf', 'apply', '--server-side',
        '--field-manager=cbox-platform', '--force-conflicts', '-f', '-'];
}

it('waits out a webhook that has not started answering yet', function (): void {
    // MEASURED after a restart: cert-manager's Deployment reported Available —
    // the replica count satisfied from a status that had not caught up — while
    // its webhook pod was not listening, and every object needing it was refused
    // with `connection refused`. Waiting on the Deployment waits on the wrong
    // thing; the apply itself is the only honest probe.
    $refused = new CommandResult(
        ran: true, exitCode: 1, output: '',
        errorOutput: 'Internal error occurred: failed calling webhook "webhook.cert-manager.io": '
            .'failed to call webhook: Post "https://cert-manager-webhook...": connect: connection refused',
    );

    $runner = new class($refused) extends FakeCommandRunner
    {
        public int $applies = 0;

        public function __construct(private readonly CommandResult $refused) {}

        public function run(array $command, int $timeout = 30, ?string $input = null): CommandResult
        {
            $this->calls[] = $command;
            $this->inputs[] = $input;

            if (! in_array('apply', $command, true)) {
                return new CommandResult(ran: true, exitCode: 0, output: '', errorOutput: '');
            }

            $this->applies++;

            // Answering on the third attempt, as a pod that is starting does.
            return $this->applies >= 3
                ? new CommandResult(ran: true, exitCode: 0, output: 'configured', errorOutput: '')
                : $this->refused;
        }
    };

    $outcome = (new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0,
        admissionAttempts: 5, admissionDelay: 0))->apply(oneObject());

    expect($outcome->succeeded)->toBeTrue()
        ->and($runner->applies)->toBe(3);
});

it('does not retry a webhook that looked at the object and refused it', function (): void {
    // A webhook that REJECTS says what is wrong and means it. Trying again would
    // produce the same refusal more slowly, and hide the sentence that explains
    // it behind a minute of waiting.
    $denied = new CommandResult(
        ran: true, exitCode: 1, output: '',
        errorOutput: 'admission webhook "vpolicy.kb.io" denied the request: spec.replicas must be positive',
    );

    $runner = (new FakeCommandRunner)->stage(applyCommand(), $denied);

    $outcome = (new NodeKubectl($runner, establishAttempts: 0, establishDelay: 0,
        admissionAttempts: 5, admissionDelay: 0))->apply(oneObject());

    expect($outcome->succeeded)->toBeFalse()
        ->and($outcome->failure)->toContain('must be positive')
        // One attempt, not six.
        ->and(count(array_filter($runner->calls, fn (array $c): bool => in_array('apply', $c, true))))->toBe(1);
});

it('asks the gateway the way a browser would, and accepts any answer', function (): void {
    // Every structural signal said ready — Deployment Available, Gateway
    // Programmed, pods 2/2 — while requests could not connect for well over a
    // minute. A 404 is a healthy gateway with no route for that hostname, which
    // is exactly what an empty platform should say; waiting for a 200 would mean
    // waiting for somebody to deploy something.
    $probe = new FakeHttpProbe(answering: true);

    expect($probe->answers('http://127.0.0.1:18080/'))->toBeTrue();

    $silent = new FakeHttpProbe(answering: false);

    expect($silent->answers('http://127.0.0.1:18080/'))->toBeFalse();
});
