---
title: Scale to zero
weight: 33
description: How a laptop holds fifteen projects at once, and what changes when a project idles down.
---

# Scale to zero

```yaml
name: shop
image: shop:dev
port: 8080
scale_to_zero: true
idle_seconds: 60
```

The web process idles down to nothing after a minute without a request, and the
next request brings it back.

## Why it matters more locally than in production

On a cell this rehearses a production feature. On a laptop it is the difference
between installing **every** project you work on and installing the two you are
working on today. An idle project costs nothing.

## What it compiles to

KEDA and its HTTP add-on, both pinned in
[configuration](../configuration/reference.md). The route points at the HTTP
interceptor, which buffers the first request while the pod starts. A cold start
is a real pod start — measured at a few seconds — not a resumed process.

Only the **web** process idles. Workers keep running: a queue that stops draining
because nobody visited the site is not a feature.

## Reading the status

```
◐ shop — idle, wakes on the next request.
```

That is not the same as:

```
○ shop — asleep. `cbox wake` brings it back.
```

**Idle** is scale-to-zero doing its job — leave it alone. **Asleep** is `cbox
sleep`: deliberately put away, nothing watching, and it will not come back on its
own. The cluster tells them apart by whether a scaler exists, not by the replica
count, which is zero in both cases. See [What a deploy
does](../core-concepts/lifecycle.md).

## Turning it off

Remove the key and redeploy. The scaler is removed as part of the same deploy —
this matters, because a scaler left behind keeps owning the replica count and
will take the workload back to zero seconds after a successful apply, leaving it
unreachable and unwakeable.

```
  ✓ shop deployed — 14 objects.
      removed HTTPScaledObject/shop — the manifest no longer asks for it.
```

If you want to know what a change will take away before making it,
`cbox deploy --dry-run` says so and changes nothing.
