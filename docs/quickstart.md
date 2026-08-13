---
title: Quickstart
weight: 2
description: From an empty directory to an application answering over HTTPS on a real Kubernetes cluster.
---

# Quickstart

This is the engine driven through `cbox`, the binary that embeds it. If you are
adding the engine to your own application instead, read
[Installation](getting-started/installation.md) first — the commands below are
the same either way, as `php artisan local:*`.

## 1. Check the machine

```bash
cbox local doctor
```

It reports what it measured on the machine in front of it: whether a container
runtime is running, how much it has been given, whether `*.cbox.test` resolves.
Nothing is inferred from what is usually true, and everything it can fix, it
tells you how to fix.

## 2. Bring the cluster up

```bash
cbox local up
```

One kind cluster per machine, created if it is not there and started if it is.
The addons — Gateway API, Envoy Gateway, cert-manager, CloudNativePG, KEDA — are
installed from manifests that ship with this package, pinned to the versions a
Cortex cell runs.

This takes a couple of minutes the first time and seconds afterwards.

## 3. Teach the machine to resolve the hostnames

```bash
cbox local setup
```

Asks for a password **once**, to write one file: `/etc/resolver/cbox.test`. That
is how macOS is told where to ask about a domain, and without it your browser
cannot resolve the hostname your project is served on. Nothing else this package
does needs elevation.

## 4. Write a manifest

`cbox.yaml`, beside your application:

```yaml
name: demo
image: nginx:1.29-alpine
port: 80
```

Three lines is a whole project. Every other key has a default, and
[the manifest reference](core-concepts/the-manifest.md) lists them.

## 5. Deploy

```bash
cbox local deploy
```

```
  ✓ demo deployed — 14 objects.
      https://demo.cbox.test:18443
```

The hostname is real, the certificate is real, and it is signed by an authority
this machine created — `cbox local trust` shows how to trust it, if your browser
does not already.

## 6. See what is running

```bash
cbox local status
```

```
  ● [cbox] running — kubectl context kind-cbox

  ● demo — 1/1 running.
```

## 7. Put it away

```bash
cbox local sleep      # stops the compute, keeps the data
cbox local wake       # brings it back
cbox local remove     # takes the project and its data off the cluster
```

`sleep` is the one to reach for between projects: a laptop can hold many
projects at once as long as the idle ones cost nothing.

## What to read next

- [Running a project](cookbook/running-a-project.md) — a real application, with
  a database and a queue worker.
- [Core concepts](core-concepts/_index.md) — what the deploy actually did.
