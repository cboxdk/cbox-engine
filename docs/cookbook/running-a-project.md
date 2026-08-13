---
title: Running a real application
weight: 31
description: A Laravel application from source, with Postgres and a queue worker, and how to run commands inside it.
---

# Running a real application

A manifest with everything a normal application needs:

```yaml
name: shop
source: true
mount: /var/www/html
port: 8080

env:
  APP_ENV: local
  APP_DEBUG: true

url: APP_URL

processes:
  queue: php artisan queue:work --tries=3

resources:
  maindb: postgres

services:
  mailpit:
    image: axllent/mailpit:v1.21
    port: 1025
```

```bash
cbox local deploy
```

## What each part bought you

**`source: true` with `mount`** puts the project directory into the container, so
an edit is live. Without it the image is the application and a change means a
rebuild.

**`url: APP_URL`** hands the project its own address as an environment variable.
The alternative is hard-coding a hostname that changes the moment the project is
deployed under a different environment.

**`processes`** gives the queue worker its own Deployment. It is not a second
copy of the web container with a different command bolted on at runtime — it is a
process the platform knows about, which is why a broken worker reads as
**degraded** rather than idle.

**`resources: maindb: postgres`** compiles to a CloudNativePG `Cluster`, the same
object a cell compiles. The connection details are injected; you do not write
them into `env`.

**`services`** are the things the platform does not model. They get an image, a
port and a name to reach them by, and nothing else — no volumes, no backups.

## Running commands inside it

```bash
cbox local artisan migrate
cbox local composer install
cbox local npm run build
cbox local run -- php -i
```

A developer platform that cannot run a command inside the thing it is running is
one people keep `kubectl` beside — and then the platform is a wrapper rather than
a tool.

## Watching it

```bash
cbox local logs            # what it is saying
cbox local logs -f         # keep the stream open
```

Logs are prefixed with the pod they came from. Three replicas interleaved into
one stream is a log nobody can read.

## When it will not start

```bash
cbox local status
```

`degraded` means something that should be running is not, and `cbox logs` says
why. The engine translates the errors that read like something else — an
`ImagePullBackOff` that is really a missing architecture says so in those words,
rather than sending you to check the tag.

## Reaching it from outside the machine

```bash
cbox local expose
```

A tunnel, for a phone on mobile data or a webhook from a payment provider calling
a backend that only exists on this laptop. `cbox local unexpose` takes it down,
and takes the credentials with the connector rather than leaving them behind.
