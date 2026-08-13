---
title: Trust boundaries
weight: 61
description: What holds what, the one thing that asks for a password, the local certificate authority, and the two features that genuinely widen the surface.
---

# Trust boundaries

## What this package holds

Nothing on anyone else's behalf. No cloud credentials, no API tokens, no remote
cluster access. It drives a container runtime on this machine and a Kubernetes
API server inside it, using the kubeconfig `kind` produced for the person who ran
it.

This is why the applier is not a service. A multi-tenant platform must not hold
cluster credentials in a web application, and one tenant's objects must be
provably unable to reach another's — neither condition exists here, and building
the boundary anyway would be complexity bought with nothing.

## The one elevation

`cbox local setup` runs `sudo tee /etc/resolver/<domain>`. One file, so macOS
knows where to ask about `*.cbox.test`.

That is the whole of it. Creating the cluster, installing addons, deploying,
building images and issuing certificates all run as you. `cbox local uninstall`
prints the `sudo rm` for that file rather than running it — removing a file it
did not have to elevate to remove would be a worse habit than asking.

## The local certificate authority

An authority is created in `~/.cbox` and signs the certificates behind
`https://<project>.cbox.test`. Its private key never leaves that directory.

`cbox local trust` shows how to trust it, and installs into the **login**
keychain rather than the System one: it needs no `sudo`, and a certificate
authority in the System store is trusted by every user on the machine, which is
more than this needs.

The authority is per-machine, created locally, and trusted by nothing outside it.

## Secrets in a manifest

`env:` values are compiled into Kubernetes Secrets in the project's namespace.
They are base64, which is encoding and not encryption — anyone who can reach the
cluster can read them, and that is everyone who can reach your Docker socket.
Treat `cbox.yaml` and the cluster as you treat a `.env` file: fine for local
credentials, not a place for production ones.

## The two features that widen the surface

**`cbox local expose`** puts a tunnel in front of a project so it can be reached
from the internet — that is the point of it, and the exposure is real for as long
as it runs. `cbox local unexpose` takes it down and takes the credentials away
with the connector, in that order.

**`mounts:`** gives a container a host directory. It is your machine's filesystem
inside a container you are running; the container can write there.

Both are opt-in, and neither is on unless a manifest or a command asks.

## Supply chain

`composer qa` runs, and CI enforces:

- a **license gate** that fails on any dependency without a permissive license,
  handling SPDX dual-licensing as OR;
- a deterministic **CycloneDX 1.5 SBOM** (`sbom.json`) generated from
  `composer.lock`, regenerated in CI and failed on drift;
- **`composer audit --no-dev`**.

Addon manifests ship **rendered and pinned**, so installing one reaches no chart
repository and cannot resolve to whatever is newest today.

## What this does not claim

No formal threat model, no compliance mapping, no conformance matrix, and no
independent review. There is no boundary here between mutually distrusting
parties for one to be written about. If this package ever grows one, the claim
will arrive with the evidence.
