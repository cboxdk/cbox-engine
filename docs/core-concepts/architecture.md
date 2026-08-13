---
title: Architecture
weight: 21
description: The chain from cbox.yaml to running objects, which repository owns each link, and why the compiler is shared.
---

# Architecture

## The chain

```
cbox.yaml
   │  ProjectManifestReader — reads it, refuses what Kubernetes would refuse
   ▼
ProjectManifest            (this package)
   │  ->toServiceSpec()
   ▼
ServiceSpec                (cboxdk/platform — the SHARED model)
   │  ServiceCompiler
   ▼
Manifest[]                 (cboxdk/platform — the SHARED compiler)
   │  ProjectDeployer adds databases, sidecars, gateway listeners, the CA copy
   ▼
ManifestDocument[]         (this package)
   │  Kubernetes::apply — server-side apply, one field manager
   ▼
a kind cluster             (this package: KindCluster)
```

## Why the middle is somebody else's

`cboxdk/platform` holds the model and the compiler, and the hosted platform uses
the same package. That is what makes "it runs the way production runs it" a fact
rather than an aspiration: the objects are not similar, they are produced by the
same code from the same types.

What differs is what a **target** declares it can do. A local target says there
is no snapshotting runtime, that the gateway is this one, that these are the
published ports. The compiler reads those capabilities and decides accordingly —
so scale-to-zero compiles to KEDA locally and can compile to something else on a
cell, without either side special-casing the other.

This is also the seam where two repositories can silently disagree. The label
prefix the compiler stamps and the selector a consumer reads are written in
different places; when the prefix moved once, the reader kept selecting the old
one and reported "no workloads" for a healthy deployment. Neither side's tests
could see it, because each was right about its own half.

## What this package owns

| Namespace | What it is |
|---|---|
| `Kind` | the cluster: create, start, stop, destroy, and which phase it is in |
| `Kubernetes` | apply, read, list, logs, exec — the handoff to the API server |
| `Platform` | the capabilities a local target declares to the shared compiler |
| `Project` | `cbox.yaml` → typed intent, and everything a deploy assembles |
| `Addons` | installing the pinned Gateway API, cert-manager, CNPG and KEDA |
| `Doctor` | what this machine can and cannot do, measured |
| `Console` | the 23 `local:*` commands |
| `Contracts` | the six seams, all bound in the provider |

`resources/addons` ships the **rendered** manifests rather than chart references,
so installing an addon reaches no chart repository and cannot resolve to
whatever is newest today. They ship with the engine rather than with a consumer,
because a copy per consumer is a copy that drifts.

## Applying, and why not a bridge

Cortex reaches clusters only through a Go service, for reasons that do not hold
locally: a multi-tenant platform must not hold cluster credentials in a web
application, and one tenant's objects must be provably unable to reach another's.
Here there is one cluster, it belongs to the person at the keyboard, and they
already have root on it.

What was worth taking from that service is its **semantics**, and those are kept:
server-side apply under one field manager, a dry run that goes through the real
admission chain, and refusing rather than guessing.
