<?php

declare(strict_types=1);

namespace Cbox\Engine\Tests;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\ApplyOutcome;
use Cbox\Engine\ValueObjects\ManifestDocument;

/**
 * A cluster that accepts everything and remembers what it was asked.
 *
 * Deny-by-default is right for the command runner, where the command IS the
 * contract. Here the question is which OBJECTS a caller reached for, so it
 * answers and records.
 */
class RecordingKubernetes implements Kubernetes
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<ManifestDocument> */
    public array $applied = [];

    /**
     * Applies and deletes in the order they happened.
     *
     * SEPARATE ARRAYS CANNOT SHOW AN ORDER, and some of what this platform does
     * is only correct in one — a gateway has to stop naming a certificate
     * before the certificate goes.
     *
     * @var list<string>
     */
    public array $events = [];

    public bool $applySucceeds = true;

    public function apply(array $manifests, bool $dryRun = false): ApplyOutcome
    {
        if (! $this->applySucceeds) {
            return new ApplyOutcome(succeeded: false, applied: 0, output: '', failure: 'refused');
        }

        $this->applied = [...$this->applied, ...$manifests];

        foreach ($manifests as $manifest) {
            $this->events[] = 'apply '.$manifest->kind().'/'.$manifest->name();
        }

        return new ApplyOutcome(succeeded: true, applied: count($manifests), output: '');
    }

    public function delete(string $kind, string $name, string $namespace): bool
    {
        $this->deleted[] = $kind.'/'.$name;
        $this->events[] = 'delete '.$kind.'/'.$name;

        return true;
    }

    /**
     * Secrets this cluster holds, by name.
     *
     * @var array<string, ManifestDocument>
     */
    public array $secrets = [];

    public function read(string $kind, string $name, string $namespace): ?ManifestDocument
    {
        if ($kind === 'secret' && isset($this->secrets[$name])) {
            return $this->secrets[$name];
        }

        // The namespace exists, so a dry run is not short-circuited; the CA
        // secret does not, so nothing is copied. Both are what these tests want.
        return $kind === 'namespace'
            ? ManifestDocument::fromArray(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $name]])
            : null;
    }

    /** @var list<string> */
    public array $tailed = [];

    public string $logLine = "a line from the workload\n";

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
        $this->tailed[] = $namespace.' '.$selector.($follow ? ' -f' : '');

        $onOutput($this->logLine);

        return true;
    }

    /** @var list<ManifestDocument> */
    public array $listed = [];

    /**
     * Answers for a particular selector, when one list is not enough.
     *
     * A fake that returns the same objects whatever it is asked hides the bug
     * where the wrong selector is used — which is exactly the bug that would let
     * a tunnel's Deployment be counted as one of a project's own.
     *
     * @var array<string, list<ManifestDocument>>
     */
    public array $listedBySelector = [];

    /**
     * @return list<ManifestDocument>
     */
    public function list(string $kind, string $selector, string $namespace = ''): array
    {
        return $this->listedBySelector[$selector] ?? $this->listed;
    }

    public function serves(string $apiVersion, string $kind): bool
    {
        return true;
    }

    /** @var list<string> */
    public array $executed = [];

    public int $execExit = 0;

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
        $this->executed[] = $namespace.' '.$selector.' :: '.implode(' ', $command).($tty ? ' [tty]' : '');

        return $this->execExit;
    }

    /**
     * @param  list<string>  $namespaces
     */
    public function waitForWorkloads(array $namespaces, int $seconds = 180): bool
    {
        return true;
    }
}
