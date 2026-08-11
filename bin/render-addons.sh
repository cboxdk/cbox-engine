#!/usr/bin/env bash
# Render every addon the local cluster needs to plain manifests, once, into
# resources/addons/.
#
# ADAPTED FROM CORTEX'S bin/render-addons.sh, deliberately rather than written
# fresh. That script carries lessons paid for on live tenants — the certgen hook
# Envoy Gateway cannot start without, the CRD collision between the chart and the
# Gateway API bundle, `--include-crds`, dropping non-manifest documents loudly —
# and every one of them applies here. Rewriting it would have reproduced the
# bugs, one at a time, on somebody's laptop instead of a cell.
#
# WHAT DIFFERS FROM CORTEX: the subset. A kind cluster brings its own CNI and
# storage, and there is no cloud to talk to, so flannel, the Hetzner CSI and the
# provider pieces are not here. The three that ARE here are pinned to the same
# versions Cortex runs, because that is the parity this whole product claims.
#
# Usage: bin/render-addons.sh
set -euo pipefail
cd "$(dirname "$0")/.."

OUT=resources/addons
mkdir -p "$OUT"

# YAML in, JSON out. The engine reads these on every `cbox up`, and megabytes of
# YAML through a pure-PHP parser is seconds of work to produce something that
# never changes between renders. The conversion also VALIDATES: a chart that
# renders something unparseable fails here, where somebody is watching.
to_json() {
  python3 - "$OUT/$1" <<'CONVERT'
import json, sys, yaml

path = sys.argv[1]

with open(path) as handle:
    # Documents only. `helm template` emits empty documents for templates that
    # rendered to nothing, and a null here would reach the applier as an object
    # with no kind.
    docs = [d for d in yaml.safe_load_all(handle) if d]

# And MANIFESTS only. `helm template` against an OCI chart prints "Pulled:" and
# "Digest:" to stdout alongside the manifests, and they parse as perfectly valid
# YAML mappings. Dropped LOUDLY: silently discarding a document is how a real
# addon goes missing.
manifests = [d for d in docs if isinstance(d, dict) and "kind" in d and "apiVersion" in d]

for dropped in [d for d in docs if d not in manifests]:
    print(f"    (not a manifest, dropped: {sorted(dropped)[:4]})")

json.dump(manifests, open(path.removesuffix(".yaml") + ".json", "w"), indent=1, sort_keys=True)
print(f"    {path.removesuffix('.yaml').split('/')[-1]}.json <- {len(manifests)} documents")
CONVERT
  rm -f "$OUT/$1"
}

# A version that resolves to nothing renders whatever chart is newest today,
# without saying so. Refuse.
version() {
  value=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config('cbox.addons.$1');")

  if [ -z "$value" ]; then
    echo "cbox.addons.$1 is not set, so this would render whatever chart is" >&2
    echo "newest today rather than the version this platform is pinned to." >&2
    exit 1
  fi

  echo "$value"
}

GATEWAY_API=$(version gateway_api)
GATEWAY=$(version gateway)
CERT_MANAGER=$(version cert_manager)
CNPG_CHART=$(version cnpg_chart)
KEDA_CHART=$(version keda_chart)
KEDA_HTTP_CHART=$(version keda_http_chart)

echo "==> rendering into $OUT"

fetch() {
  echo "    $1 <- $2"
  curl -fsSL "$2" > "$OUT/$1"
}

# Hooks are excluded by default and INCLUDED where the chart cannot work without
# them. A helm hook is an instruction to helm, and there is no helm here to read
# it, so a rendered hook is just an object — pure loss for most charts.
#
# Envoy Gateway is the exception, and finding out cost a live tenant: its certgen
# hook GENERATES the control-plane certificates, so without it the Deployment
# never starts, failing to mount a secret named `envoy-gateway` that nothing
# creates. A hook that makes something the chart's own workload mounts is not a
# hook, it is part of the chart.
#
# --include-crds, because a chart's crds/ directory is invisible to `template`
# without it, and a controller whose CRDs are missing crash-loops on a kind it
# cannot find.
render() {
  name=$1; chart=$2; ns=$3; ver=$4; hooks=$5; shift 5
  echo "    $name.yaml <- $chart $ver ($hooks)"

  set -- "$@" --version "$ver" --namespace "$ns" --include-crds
  [ "$hooks" = "hooks" ] || set -- "$@" --no-hooks

  helm template "$name" "$chart" "$@" > "$OUT/$name.yaml"
}

# The EXPERIMENTAL channel, because Envoy Gateway watches TLSRoute at v1 and the
# standard channel does not serve it.
fetch "gateway-api-crds.yaml" "https://github.com/kubernetes-sigs/gateway-api/releases/download/${GATEWAY_API}/experimental-install.yaml"
to_json gateway-api-crds.yaml

helm repo add jetstack https://charts.jetstack.io --force-update >/dev/null
helm repo add cnpg https://cloudnative-pg.github.io/charts --force-update >/dev/null
helm repo add kedacore https://kedacore.github.io/charts --force-update >/dev/null
helm repo update >/dev/null

# Requests are set rather than left at the charts' defaults, and on a laptop that
# matters more than on a cell: the defaults reserve most of a machine for
# components that use a fraction of it, and every project a developer runs comes
# out of what is left.
render envoy-gateway oci://docker.io/envoyproxy/gateway-helm envoy-gateway-system "$GATEWAY" hooks \
  --set deployment.envoyGateway.resources.requests.cpu=25m \
  --set deployment.envoyGateway.resources.requests.memory=96Mi

to_json envoy-gateway.yaml

render cert-manager jetstack/cert-manager cert-manager "$CERT_MANAGER" no-hooks \
  --set crds.enabled=true \
  --set-json 'extraArgs=["--enable-gateway-api"]' \
  --set resources.requests.cpu=25m \
  --set resources.requests.memory=48Mi \
  --set webhook.resources.requests.cpu=15m \
  --set cainjector.resources.requests.cpu=15m

to_json cert-manager.yaml

# CloudNativePG, and the reason it is here rather than a plain Postgres pod: it
# is what Postgres compiles to in production. It also happens to be the only
# thing in this set that manages its instances as individual pods rather than a
# StatefulSet — which is what lets it replace a member on a dead node instead of
# waiting for one. See the package's docs/core-concepts/databases.md.
render cnpg cnpg/cloudnative-pg cnpg-system "$CNPG_CHART" no-hooks \
  --set resources.requests.cpu=25m \
  --set resources.requests.memory=64Mi

to_json cnpg.yaml

# KEDA and its HTTP add-on: what scale-to-zero is made of. The add-on's
# interceptor is what holds a request open while a pod that was at zero starts,
# so a wake looks like a slow response rather than a failed one.
render keda kedacore/keda keda "$KEDA_CHART" no-hooks \
  --set resources.operator.requests.cpu=25m \
  --set resources.operator.requests.memory=64Mi \
  --set resources.metricServer.requests.cpu=15m \
  --set resources.webhooks.requests.cpu=15m

to_json keda.yaml

render keda-add-ons-http kedacore/keda-add-ons-http keda "$KEDA_HTTP_CHART" no-hooks \
  --set interceptor.replicas.min=1 \
  --set scaler.replicas=1

to_json keda-add-ons-http.yaml

# ONE authority for the Gateway API CRDs, and it is the pinned bundle.
#
# Envoy Gateway's chart has an all-or-nothing `crds.enabled`: it ships its own
# CRDs, which nothing else provides, together with a COPY of the Gateway API's,
# which the bundle provides better. Measured on a fresh cluster that copy was 11
# of the 13 and omitted the TLSRoute the controller itself watches — so with both
# present, behaviour depends on which landed last.
#
# Filtered by IDENTITY rather than by matching group names. Matching the string
# `group: gateway.networking.k8s.io` left three objects behind: two CRDs in
# `gateway.networking.X-k8s.io`, a different group one character away, and a
# ValidatingAdmissionPolicy. Those collide on Kind/Name, which is the key applied
# state is stored under, so one copy would silently displace the other.
python3 - "$OUT/envoy-gateway.json" "$OUT/gateway-api-crds.json" <<'DEDUPE'
import json, sys

target, authority = sys.argv[1], sys.argv[2]

def identity(doc):
    return (doc.get("kind"), doc.get("metadata", {}).get("name"))

owned = {identity(d) for d in json.load(open(authority))}
docs = json.load(open(target))
kept = [d for d in docs if identity(d) not in owned]

json.dump(kept, open(target, "w"), indent=1, sort_keys=True)
print(f"    (dropped {len(docs) - len(kept)} documents the Gateway API bundle owns)")
DEDUPE

cat > "$OUT/rendered.json" <<JSON
{
  "gateway_api": "${GATEWAY_API}",
  "gateway": "${GATEWAY}",
  "cert_manager": "${CERT_MANAGER}",
  "cnpg_chart": "${CNPG_CHART}",
  "keda_chart": "${KEDA_CHART}",
  "keda_http_chart": "${KEDA_HTTP_CHART}"
}
JSON

echo "==> done"
