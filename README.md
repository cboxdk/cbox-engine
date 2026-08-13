# Cbox Engine

The engine behind the `cbox` CLI and the Cbox Local desktop app: one Kubernetes
cluster inside Docker, running an application **the way production runs it** —
same manifest, same `cboxdk/platform` compiler, same gateway, same proxy headers,
same scaling.

It is a library, not a product. Two things consume it:

- **`cboxdk/cbox-cli`** — the standalone `cbox` binary, which is how a person,
  a server or an agent drives it.
- **`cboxdk/cbox-local`** — the desktop app, which is the same engine with a
  window in front of it.

Neither owns the engine, and that is the point: a command has one implementation
and the two front ends cannot drift.

## What is in here

`src/` is the whole of it — `Kind` (the cluster), `Kubernetes` (apply and read),
`Platform` (the capabilities a local target declares to the shared compiler),
`Project` (a `cbox.yaml` becoming typed intent), plus addons, tunnels, sandboxes
and the doctor. `src/Console` holds the 23 `local:*` commands.

`resources/addons` ships the **rendered** manifests for Gateway API, Envoy
Gateway, cert-manager, CloudNativePG and KEDA, pinned to the versions a Cortex
cell runs. They ship with the engine rather than with a consumer, because a copy
per consumer is a copy that drifts.

## Documentation

[`docs/`](docs/index.md) — start at the [quickstart](docs/quickstart.md), or
[what a deploy does](docs/core-concepts/lifecycle.md) if you want the part that
is least like other tools.

## Using it

```bash
composer require cboxdk/cbox-engine
```

The provider registers every command and merges `config/cbox.php`. The cluster's
own state lives in `~/.cbox`, not in the consuming application — the CLI and the
desktop drive **one** cluster on a machine, and a path relative to whichever is
running would give them two.

## Verification

```bash
composer qa
```

Pint, PHPStan at level max with larastan, Pest, the dependency license gate, and
`composer audit`. CI runs the same on PHP 8.4 and 8.5, and additionally checks
that the committed `sbom.json` still matches the lock it describes.
