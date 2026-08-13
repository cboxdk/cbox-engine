---
title: The six contracts
weight: 41
description: CommandRunner, ContainerRuntime, ClusterManager, Kubernetes, HostResolver and HttpProbe — what each owns and how to rebind one.
---

# The six contracts

All in `Cbox\Engine\Contracts`, all bound in `EngineServiceProvider`.

## CommandRunner

Every child process — `docker`, `kind`, `kubectl` — goes through here. One place
that builds a process is one place that gets the environment right: it supplies a
`HOME` when the parent has none, which a web process routinely does.

Rebind it to record commands, to run them somewhere else, or to refuse them.
`Cbox\Engine\Testing\FakeCommandRunner` is the deny-by-default implementation
this package's own suite uses.

## ContainerRuntime

Whether a runtime is there, what it reports about itself, and the architecture it
is running. Docker and OrbStack both answer.

## ClusterManager

The cluster's lifecycle: create, start, stop, destroy, and which phase it is in.
The default is `KindCluster` — one cluster per machine, named `cbox`.

A failed listing is not an empty one, and only the exit code tells them apart:
with the runtime stopped, `kind get clusters` runs, exits 1 and prints to stderr,
so reading stdout alone reported the cluster **absent** and offered to build one
over a cluster sitting there intact.

## Kubernetes

The handoff to the API server: apply, delete, read, list, logs, exec, and whether
a kind is served yet.

- **Apply is server-side, under one field manager**, with a dry run that goes
  through the real admission chain.
- **`serves()` reads the KIND column** the API server reports. Pluralisation is
  not guessable — Ingress/ingresses, Policy/policies — and comparing a singular
  kind against `api-resources -o name`, which prints the plural, answers "no" to
  everything.
- **`list()` filters by kind and namespace**, because a caller that sweeps by kind
  and is handed something else will act on it.

## HostResolver

How this machine is taught to resolve a domain, and whether it currently does.
On macOS the file is one entry in `/etc/resolver`. This is the seam that makes
the platform-specific part replaceable rather than a conditional in the middle of
a command.

`resolves()` asks the **outcome**, not the artefact, and the difference is not
academic: any resolver covering the parent `.test` answers for this domain too.
A machine with one needs nothing from us, and a check that read only its own file
told such a machine that projects would open in curl and not in a browser while
they were opening in a browser.

## HttpProbe

Asking a URL whether it is answering yet, for the readiness a deploy waits on.
`FakeHttpProbe` ships for tests.

## Replacing one

Bind it after the provider has registered:

```php
use Cbox\Engine\Contracts\CommandRunner;

$this->app->bind(CommandRunner::class, MyCommandRunner::class);
```

Two things worth knowing before you do:

- **The command is the contract.** `docker info` and
  `docker info --format json` are different questions, and a runner that treats
  them as one will pass tests and mislead callers.
- **Return the API server's own words.** When an apply fails it is almost always
  a webhook, an immutable field or a missing CRD saying something specific, and a
  runner that replaces that with "apply failed" has taken away the only useful
  part.
