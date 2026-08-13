---
title: Security
weight: 60
description: What the trust boundary actually is for a local development tool, and the honest limits of it.
---

# Security

This is a **local development tool**. Being clear about that is the most useful
security statement it can make, because almost everything else follows from it.

- **[Trust boundaries](trust-boundaries.md)** — who holds what, what asks for a
  password, and where the honest limits are.

## The short version

There is one cluster, it runs in a container on this machine, and the person at
the keyboard already has root on it. There is no multi-tenancy to enforce, no
credential this package holds on somebody else's behalf, and no boundary between
users to defend — so this package does not pretend to one.

What it does take seriously is the machine: nothing is elevated except the single
file that teaches the resolver a domain, certificates are issued by an authority
created here rather than a shared one, and every version it installs is pinned.

## Reporting something

Use GitHub's private vulnerability reporting on
[cboxdk/cbox-engine](https://github.com/cboxdk/cbox-engine). Best effort — this
is a development tool maintained alongside the products that use it, and there is
no staffed security mailbox or response-time commitment behind it. Saying
otherwise would be worse than saying nothing.
