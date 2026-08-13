---
title: A package you are editing next door
weight: 32
description: Why a composer path repository dangles inside a container, and what the engine does about it automatically.
---

# A package you are editing next door

Working on a package and its consumer at once is ordinary:

```json
{
    "repositories": [
        { "type": "path", "url": "../laravel-id" }
    ]
}
```

**A composer path repository is a symlink out of the project.**
`vendor/cboxdk/laravel-id` becomes a link to a sibling checkout. It resolves
perfectly on the machine and dangles inside the container, because only the
project directory is mounted.

And it fails in the cruellest way. The application boots under a local PHP and
the pod dies with *"Failed to open stream: No such file or directory"* naming a
file that is plainly there — the developer checks the path, finds it, and has
nowhere to go. Measured on a real project: a queue worker crash-looping for a day
over a package the host could see the whole time.

## What the engine does

Nothing to configure. When a project is deployed with `source: true`, the engine
reads the path repositories **declared in `composer.json`** — not by scanning a
vendor tree of thousands of directories — and:

1. **Mounts each sibling** where the link actually lands. A link resolving to
   `/var/www/laravel-id` has to find the sibling at `/var/www/laravel-id`, which
   is outside the application root.
2. **Adds those paths to `open_basedir`**, via `PHP_OPEN_BASEDIR_EXTRA`.

The second step is the one that is easy to miss, and the failure it prevents is
identical to the first: the directory is mounted, PHP can see it, and
`open_basedir` refuses to include from it.

```
include(): open_basedir restriction in effect.
File(/var/www/laravel-id/src/IdServiceProvider.php) is not within the allowed path(s)
```

Both halves are needed, and both happen without being asked.

## What is left alone

Two forms are not guessed at:

- **An absolute url** is somebody's own machine and cannot be made relative to
  the project.
- **A wildcard** (`../packages/*`) is a set of repositories rather than one.

Neither is mounted. Guessing would be worse than not: a wrong mount shadows a
directory with a copy of something else.

A sibling that happens to live *inside* the project is skipped too — it is
already mounted with the project, and mounting it over itself would shadow it.
