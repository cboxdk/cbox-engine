---
title: Reference
weight: 51
description: Every key in config/cbox.php, the environment variable behind it, and why the versions are pinned.
---

# Reference

`config/cbox.php` is merged by the provider. Publishing it is not required.

## Addon versions

```php
'addons' => [
    'gateway_api'     => 'v1.5.1',
    'gateway'         => 'v1.8.3',
    'cert_manager'    => 'v1.21.0',
    'cnpg_chart'      => '0.29.0',
    'keda_chart'      => '2.20.1',
    'keda_http_chart' => '0.15.0',
],
```

**Pinned to the same values Cortex runs**, which is the parity claim made
concrete: an application meets the same Envoy Gateway, the same Gateway API and
the same cert-manager on a laptop as it does on a cell. A version that drifts
here quietly turns "it worked locally" back into a sentence nobody can act on.

| Key | What it is |
|---|---|
| `gateway_api` | the Gateway API CRDs |
| `gateway` | Envoy Gateway, which programs them |
| `cert_manager` | issues the certificates behind `https://…cbox.test` |
| `cnpg_chart` | CloudNativePG — Postgres compiles to its `Cluster` on both sides |
| `keda_chart` | KEDA |
| `keda_http_chart` | the KEDA HTTP add-on — what scale-to-zero is made of |

A version resolving to nothing is the dangerous case rather than the loud one:
rendering a chart without an explicit version silently takes whatever is newest
today. The render script refuses instead, and what ships is the **rendered**
manifest, so installing an addon reaches no chart repository at all.

## Tunnel

```php
'tunnel' => [
    'image' => env('CBOX_TUNNEL_IMAGE', 'cloudflare/cloudflared:2026.7.3'),
],
```

| Variable | Default |
|---|---|
| `CBOX_TUNNEL_IMAGE` | `cloudflare/cloudflared:2026.7.3` |

The connector `cbox expose` runs. Pinned like everything else: `latest` on
a component that sits in the request path means two developers debugging the same
problem on different builds.

## What is deliberately not configurable

- **The cluster name** (`cbox`) and its kubectl context (`kind-cbox`). One cluster
  per machine is the design, not a default.
- **Where state lives** (`~/.cbox`). The CLI and the desktop app must find the
  same cluster; a configurable path is how they would end up with two.
