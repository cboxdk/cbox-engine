---
title: Requirements
weight: 3
description: The PHP, Laravel and host requirements the resolver enforces, and the two things on the machine that it cannot.
---

# Requirements

Everything here is what `composer.json` actually declares. Nothing is a
recommendation.

## PHP

| | |
|---|---|
| PHP | `^8.4` |

**8.4 is a floor, not a preference.** The source uses `new Foo()->bar()`, which
is a parse error before 8.4 — a package that claimed `^8.3` would install on 8.3
and then fail to load, which is a worse answer than the resolver refusing.
Developed and tested on 8.4 and 8.5.

## Laravel

| Package | Constraint |
|---|---|
| `illuminate/console` | `^12.0 \|\| ^13.0` |
| `illuminate/contracts` | `^12.0 \|\| ^13.0` |
| `illuminate/http` | `^12.0 \|\| ^13.0` |
| `illuminate/support` | `^12.0 \|\| ^13.0` |

The current major and the previous one, so this installs into an application on
either.

## Other runtime dependencies

| Package | Constraint | What it is for |
|---|---|---|
| `cboxdk/platform` | `^0.11.0` | the shared model and compiler — the parity claim |
| `symfony/process` | `^7.0 \|\| ^8.0` | every child process: docker, kind, kubectl |
| `symfony/yaml` | `^7.0 \|\| ^8.0` | reading `cbox.yaml` |

No PHP extensions beyond a default build. `posix` is used where it exists, to
find a home directory when the environment has no `HOME`, and the code works
without it.

## What the resolver cannot check

Two things have to be on the machine, and neither is a Composer dependency:

- **A container runtime** — Docker or OrbStack. The cluster is a container.
- **`kind` and `kubectl`** on the `PATH`.

`cbox local doctor` checks all of it and names what is missing. It is the first
command to run on a new machine, and the only one that can explain why the rest
do not work.

## Platform

macOS and Linux. The one platform-specific piece is how a machine is taught to
resolve `*.cbox.test`: on macOS that is `/etc/resolver/cbox.test`, written by
`cbox local setup`. See [HostResolver](extension-points/contracts.md) for the
seam that decides it.
