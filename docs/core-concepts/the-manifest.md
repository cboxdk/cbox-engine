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
