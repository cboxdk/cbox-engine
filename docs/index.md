---
title: Cbox Engine
weight: 1
description: The library behind the cbox CLI and the Cbox Local desktop app — one Kubernetes cluster in Docker, running an application the way production runs it.
---

# Cbox Engine

Cbox Engine runs an application on a local Kubernetes cluster **the way production
runs it**: the same manifest, the same compiler, the same gateway, the same proxy
headers, the same scaling.

It is a library, not a product. Two things consume it:

- **`cboxdk/cbox-cli`** — the standalone `cbox` binary, which is how a person, a
  server or an agent drives it.
- **`cboxdk/cbox-local`** — the desktop app, which is the same engine with a
  window in front of it.

Neither owns the engine, and that is the point: a command has one implementation,
so the binary and the window cannot drift.

## The mental model

A developer writes one file, `cbox.yaml`. Everything follows from it.

```
cbox.yaml  →  ProjectManifest  →  ServiceSpec  →  Kubernetes objects  →  kind cluster
   your        this package       cboxdk/platform     the compiler        one per machine
   intent      reads and          the SHARED model    decides the         created by
               validates it       Cortex uses too     objects             `cbox up`
```

The middle of that chain is the parity claim, made concrete. `cboxdk/platform` is
the same compiler the hosted platform uses, so an application does not meet a
local imitation of production — it meets the same objects, from the same code,
against the same addon versions ([Configuration](configuration/reference.md)
pins them).

What this package adds on either side of the compiler is the local half: creating
and holding a kind cluster, reading `cbox.yaml`, applying objects, and the 23
`local:*` commands a person actually types.

## What it is not

It does not talk to Cortex, hold cloud credentials, or manage remote clusters.
There is one cluster, it lives in Docker on this machine, and the person at the
keyboard already has root on it — see [Trust boundaries](security/_index.md) for
what follows from that.

## Sections

- **[Getting started](getting-started/_index.md)** — installing it into an
  application, and the fakes its own suite uses.
- **[Core concepts](core-concepts/_index.md)** — the architecture, the manifest,
  and what a deploy does.
- **[Cookbook](cookbook/_index.md)** — running a project, linking a package you
  are editing next door, scaling to zero.
- **[Extension points](extension-points/_index.md)** — the six contracts, and
  replacing one.
- **[Configuration](configuration/_index.md)** — every key in `config/cbox.php`.
- **[Security](security/_index.md)** — the trust boundary, the local authority,
  and the one thing that asks for a password.

Start with the [quickstart](quickstart.md) if you would rather see it work first.
