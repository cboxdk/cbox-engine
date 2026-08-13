---
title: Extension points
weight: 40
description: The six contracts everything resolves through, and how to replace one.
---

# Extension points

Every capability that touches the outside world is an interface, bound in
`EngineServiceProvider` and resolved from the container. Nothing depends on a
concrete class, which is what makes both the fakes and a host override possible.

- **[The six contracts](contracts.md)** — what each one is for, and what
  replacing it changes.
