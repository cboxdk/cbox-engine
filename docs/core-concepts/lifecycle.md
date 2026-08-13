---
title: What a deploy does
weight: 23
description: Apply and sweep, why the sweep runs first, and the four states a project can be in.
---

# What a deploy does

A deploy compiles the manifest, **takes away what the manifest stopped asking
for**, and applies the rest.

## Server-side apply never removes

An apply updates; it does not subtract. Anything applied by a previous deploy and
missing from this one simply stays — the API server has no idea it left the set.
Without a sweep, a deploy could only ever add.

**A leftover object is not untidiness. It is a second controller still acting on
the workload.** Measured on a real cluster: a project with `scale_to_zero: true`
was changed to `replicas: 2` and redeployed. The apply was correct and two pods
went ready; nine seconds later the `HTTPScaledObject` nobody had removed took the
Deployment back to zero and held it, and the route no longer pointed at the
interceptor that could have woken it. The application was unreachable,
unwakeable, and had just been deployed successfully.

## The sweep runs before the apply

Sweeping afterwards is a race the sweep loses — that is exactly the sequence
above, and it leaves the workload at zero with nothing remaining to raise it,
correct only on the *next* deploy. Removing the leftover first means nothing is
left to argue with the apply.

What it will remove, and what it will not:

| | |
|---|---|
| **Removed** | Deployment, Service, HTTPRoute, HTTPScaledObject, ScaledObject, HorizontalPodAutoscaler, PodDisruptionBudget, Certificate |
| **Reported, never removed** | StatefulSet, CloudNativePG `Cluster` |
| **Never considered** | everything else, including Pods and ReplicaSets |

Three rules make that safe:

- **An allow-list of kinds, never "everything carrying our label".** Pods and
  ReplicaSets inherit the pod template's labels, so a label-scoped sweep would
  delete the running pods of a healthy project and call it housekeeping.
- **Nothing that holds data is ever deleted here.** Dropping a line from a YAML
  file is not consent to destroy a database. A retained object is named, and the
  Service that reaches it is kept too, so it is not left half-dismantled.
- **It refuses to subtract against a set that compiled to nothing** — an empty
  desired state is a manifest that failed to read, not a project asking for
  nothing.

It is scoped to the project's own namespace, and there is one namespace per
environment, so a sweep cannot reach a sibling branch.

```
  ✓ demo deployed — 14 objects.
      removed HTTPScaledObject/demo — the manifest no longer asks for it.
      Cluster/maindb is still running and no longer in this manifest.
      It holds data, so it was left alone. `cbox remove` takes it with the project.
```

`--dry-run` works the same answer out and changes nothing. "What would this take
away" is the question worth asking before a deploy, and it cannot be read off the
file — it is the difference between the file and the cluster.

## The four states

`cbox status` reports one of four things per project, and telling them
apart is the whole point: two of them show no web pod.

| State | Means | What to do |
|---|---|---|
| **running** | everything it wants is up | nothing |
| **idle** | the web process is at zero and a scaler is watching for a request | nothing — it wakes itself |
| **asleep** | deliberately put away; nothing is watching | `cbox wake` |
| **degraded** | something that should be running is not | `cbox logs` |

**Idle and asleep are told apart by the cluster, not by the file.** A replica
count of zero looks identical either way. The `HTTPScaledObject` is the
difference, and it is only there in the idle case — `cbox sleep` compiles a set
without it and the deploy takes the old one away.

Getting this wrong is not cosmetic. `asleep` used to mean "wants zero replicas",
which is true of *any* scale-to-zero project with no worker once its web idles
down — so status told people to run `cbox wake` on projects that wake themselves,
and the distinction only survived for projects that happened to own a second
process.

Equally, a project whose queue worker is in `CrashLoopBackOff` is **degraded**,
never idle. "Idle, wakes on the next request" in front of a broken worker would
cost somebody an hour.

## Sleep, wake, remove

- **`sleep`** recompiles with the project suspended: every process pinned to
  zero, the scaler gone, the route pointed away from the interceptor. The data
  stays exactly where it was.
- **`wake`** is the same path with the boolean the other way, so the two cannot
  stop being each other's inverse.
- **`remove`** takes the project off the cluster *with its data* — which is what
  the retained-object message promises, and the promise holds.
