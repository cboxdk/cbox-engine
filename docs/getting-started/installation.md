---
title: Installation
weight: 11
description: Adding the engine to a Laravel application, what the provider registers, and where the cluster's state lives.
---

# Installation

```bash
composer require cboxdk/cbox-engine
```

`Cbox\Engine\EngineServiceProvider` is auto-discovered. There is nothing to
publish and nothing to configure to get started.

## What the provider does

**Registers the commands.** All 23 of them, under the `local:` namespace:

```
local:addons    local:artisan   local:composer  local:deploy    local:destroy
local:doctor    local:down      local:expose    local:logs      local:npm
local:prune     local:push      local:remove    local:run       local:sandbox
local:setup     local:sleep     local:status    local:trust     local:unexpose
local:uninstall local:up        local:wake
```

In your application they are `php artisan local:deploy`. In the `cbox` binary
they are `cbox local deploy` — and `cbox local:deploy`, which is the same command
reached through an argv fold rather than a second registration.

**Merges `config/cbox.php`.** Addon versions and the tunnel image, all pinned.
See the [configuration reference](../configuration/reference.md).

**Binds the six contracts** to their real implementations — the container
runtime, the command runner, the cluster manager, the Kubernetes applier, the
host resolver, the HTTP probe. Every one of them is an interface you can rebind;
see [Extension points](../extension-points/contracts.md).

## Where state lives

The cluster's own files live in **`~/.cbox`**, not in the consuming application.

This is deliberate and it is not a preference. The CLI and the desktop app drive
**one** cluster on a machine. A path relative to whichever happened to be running
would give them two — two authorities, two sets of certificates, and a project
deployed by one that the other cannot see.

`HOME` is resolved by `Cbox\Engine\Support\Home`, which asks the environment and
then the passwd database, and refuses rather than guessing. A web process
routinely has no `HOME` at all, and `''` rtrimmed into a path is `/`, which is
not a place to write a certificate authority.

## Driving it from code

The commands are thin. Anything they do, you can do:

```php
use Cbox\Engine\Project\ProjectManifestReader;
use Cbox\Engine\Project\ProjectDeployer;

$manifest = (new ProjectManifestReader)->read('/path/to/cbox.yaml');

$outcome = app(ProjectDeployer::class)->deploy($manifest);

$outcome->succeeded;         // bool
$outcome->applied;           // how many objects
$outcome->swept->removed;    // what the deploy took away
$outcome->swept->retained;   // what it refused to take away, because it holds data
```

`deploy()` takes `dryRun: true` to send everything through the API server's
admission chain and persist nothing — including working out what it *would*
remove. See [What a deploy does](../core-concepts/lifecycle.md).
