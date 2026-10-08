---
title: The manifest
weight: 22
description: Every key cbox.yaml accepts, what it defaults to, and what the reader refuses.
---

# The manifest

One file, beside the application. Three lines is a whole project:

```yaml
name: demo
image: nginx:1.29-alpine
port: 80
```

## Every key

| Key | Type | Default | What it does |
|---|---|---|---|
| `name` | string | *required* | the project, and the namespace it gets |
| `image` | string | — | the image to run |
| `build` | map | — | build one instead: `context`, `dockerfile`, `target`, `args` |
| `source` | bool | `false` | mount the project directory into the container |
| `mount` | string | — | where the project lands inside the container |
| `port` | int | `8080` | the container port the web process listens on |
| `domains` | list | derived | extra hostnames; `<name>.cbox.test` is always there |
| `url` | string | — | an env var to receive the project's own URL |
| `env` | map | `[]` | environment variables; numbers and booleans become text |
| `replicas` | int | `1` | copies of the web process, at least 1 |
| `processes` | map | `[]` | extra long-running processes: `queue: php artisan queue:work` |
| `resources` | map | `[]` | managed data: `maindb: postgres` |
| `services` | map | `[]` | things the platform does not model: an image and a port |
| `mounts` | map | `[]` | host directories the container should see |
| `scale_to_zero` | bool | `false` | let the web process idle down and wake on a request |
| `idle_seconds` | int | — | how long before it does |
| `secrets` | map | `[]` | env vars from Secrets on the platform: `APP_KEY: { secret: myapp, key: APP_KEY }` |
| `health` | map | — | the web process's probes: `path` (readiness), `liveness`, `token` |
| `release` | command | — | runs to completion before every deploy rolls: `php artisan migrate --force` |

## What the reader refuses

Refusals happen here, in front of the person editing the file, rather than at the
API server in a vocabulary they never opted into.

- **A name Kubernetes would refuse.** `Acme Corp` fails with "cannot be a project
  name", not with a message about a field.
- **A structure where a value belongs.** `env: {THING: {a: b}}` is refused: an
  environment variable is a single value.
- **A command that only means something to a shell.** A container runs a program,
  not a shell line. `migrate && serve` split naively is a program called
  `migrate` with an argument `&&`, which fails at runtime with a message about a
  missing file. Pipes, redirects and `$VAR` are refused the same way, by name.
- **A command that ends inside a quote.**
- **An unknown key**, so a typo is a refusal rather than a setting that silently
  did nothing.
- **A secret written as a value**, a variable under both `env` and `secrets`, or
  one a resource already binds — see [Secrets](#secrets).
- **A health token that is not a `secrets` entry**, including one under `env`.

Commands may be a string or a list. The string is split carefully — quoted
arguments stay whole — because making people write YAML lists for every command
is a tax they will work around.

## Processes

```yaml
processes:
  queue: php artisan queue:work
  scheduler: php artisan schedule:work
```

Each becomes its own Deployment. The distinction matters more than it looks:
scale-to-zero puts the **web** process away and nothing else, so a worker that is
down is a fault rather than idleness — see [What a deploy does](lifecycle.md).

## Resources and services

`resources` is data the platform manages and takes seriously:

```yaml
resources:
  maindb: postgres
```

Postgres compiles to a CloudNativePG `Cluster`, the same object it compiles to on
a cell, so the one thing a developer most wants to trust behaves the same.

`services` is everything else — ClickHouse, Kafka, a mail catcher — things the
platform does not model and should not pretend to:

```yaml
services:
  mailpit:
    image: axllent/mailpit:v1.21
    port: 1025
```

No volumes, no backups, no promises. What is worth keeping goes in `resources`.

## Secrets

```yaml
secrets:
  APP_KEY: { secret: myapp, key: APP_KEY }
  MAIL_PASSWORD: { secret: myapp-mail, key: MAIL_PASSWORD }
```

An environment variable from a Secret that **already exists on the platform**, in
the project's namespace (`cbox-<name>`). The file is committed, so it says where a
value lives, never the value: each entry compiles to a `secretKeyRef` on every
container of the project — the web process, every process, `cbox run` and the
release. Nothing creates, changes or removes the Secret; it is the operator's.

**Create the Secret before the first deploy.** A missing Secret or key is not
skipped: Kubernetes holds the pod in `CreateContainerConfigError`, `cbox deploy`
reports which Secret it was looking for, and the pod starts on the next deploy
once the Secret is there. Locally:

```bash
kubectl -n cbox-myapp create secret generic myapp \
  --from-literal=APP_KEY="$(php artisan key:generate --show)"
```

Refused: a variable that is not `[A-Za-z_][A-Za-z0-9_]*`, a Secret name or key
Kubernetes would refuse, any setting besides `secret` and `key` (there is no
`optional`), a variable also under `env`, also bound by a resource, or the `url`
variable.

## Health

```yaml
health:
  path: /health/ready     # readiness: may this pod take traffic?
  liveness: /up           # liveness: is the process alive at all?
  token: HEALTH_TOKEN     # optional: sent to `path` as `Authorization: Bearer`
```

Probes on the **web** process only; workers listen on nothing.

| Probe | Path | Timing |
|---|---|---|
| startup | `liveness` | every 2 s, up to five minutes — liveness and readiness wait for it |
| liveness | `liveness` | every 10 s; restarted after three misses |
| readiness | `path` | every 10 s; out of rotation after three misses |

Keep `liveness` shallow. A liveness probe that checks the database restarts every
pod the moment the database is slow.

**`token` names a variable, and it must be a `secrets` entry.** Kubernetes cannot
put a Secret into a probe header, so the platform has the container ask itself:
readiness becomes a command that reads the token from the container's own
environment and sends it with `curl`, the value never written into the pod spec.
That needs `curl` in the image — every Cbox base image has it. Without `token`,
readiness is a plain HTTP request.

## Release

```yaml
release: php artisan migrate --force
```

A command, written like a process's, that runs **once per release, to
completion, before anything rolls**:

1. Everything the release needs is applied — the namespace, Secrets, Services,
   databases — but not the Deployments. The running release keeps serving.
2. The deploy waits for the databases, then runs the command as a Job in the
   **new** release's container: same image, environment, `secrets` and bindings.
3. If it fails, the deploy stops and prints its output. Nothing rolls; the old
   pods keep running the old code.
4. If it succeeds, the Deployments roll behind their readiness probes.

The Job is named for what it runs, so deploying the same release twice does not
migrate twice, and a release that failed is retried on the next deploy. Because
the old pods still serve while it runs, a migration has to be safe for the
release before it: add, never drop or rename what the old code reads.

`cbox push` does not carry `secrets`, `health` or `release` yet; it says so and
what to do in Cortex instead.
