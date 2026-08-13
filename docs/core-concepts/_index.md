---
title: Core concepts
weight: 20
description: How the engine is put together, what a manifest may say, and what a deploy actually does to a cluster.
---

# Core concepts

- **[Architecture](architecture.md)** — the chain from a YAML file to running
  objects, and which repository owns each link.
- **[The manifest](the-manifest.md)** — every key `cbox.yaml` accepts.
- **[What a deploy does](lifecycle.md)** — apply, sweep, and the four states a
  project can be in.

The through-line: **the cluster is asked, never remembered.** There is no local
database of projects. A record of what was deployed drifts from what is deployed
the first time anybody runs `kubectl`, and then the tool is confidently wrong.
