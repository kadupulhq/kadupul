#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
E2E_DIR="$ROOT/tests/e2e"
STATE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/kadupul-rrd-proxy-e2e.XXXXXX")"
export RRD_PROXY_E2E_STATE="$STATE_DIR"
export HOST_PORT="${HOST_PORT:-8080}"
export E2E_BASE_URL="${E2E_BASE_URL:-http://localhost:${HOST_PORT}}"
DC=(docker compose -p kadupul-rrd-proxy-e2e -f "$E2E_DIR/docker-compose.yml" -f "$E2E_DIR/docker-compose.rrd-proxy.yml")

cleanup() {
    local status=$?
    if [[ "${KEEP_UP:-0}" != "1" ]]; then
        "${DC[@]}" down --volumes --remove-orphans || true
        rm -rf "$STATE_DIR"
    else
        printf 'Keeping containers and proxy keys at %s\n' "$STATE_DIR" >&2
    fi
    exit "$status"
}
trap cleanup EXIT

umask 077
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$STATE_DIR/client-private.pem" 2>/dev/null
openssl pkey -in "$STATE_DIR/client-private.pem" -pubout -out "$STATE_DIR/client-public.pem"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$STATE_DIR/proxy-private.pem" 2>/dev/null
openssl pkey -in "$STATE_DIR/proxy-private.pem" -pubout -out "$STATE_DIR/proxy-public.pem"

"${DC[@]}" up -d --build

for attempt in $(seq 1 90); do
    if curl -fsSI "http://localhost:${HOST_PORT}/" 2>/dev/null | grep -iq '^content-security-policy-report-only:'; then
        break
    fi
    if [[ "$attempt" == 90 ]]; then
        "${DC[@]}" logs --tail=200 >&2 || true
        exit 1
    fi
    sleep 2
done

RRD_PROXY_E2E_FINGERPRINT="$("${DC[@]}" exec -T php php /var/www/html/cacti/tests/e2e/rrd-proxy/setup.php)"
export RRD_PROXY_E2E_FINGERPRINT
printf 'Proxy fingerprint configured: %s\n' "$RRD_PROXY_E2E_FINGERPRINT"

cd "$E2E_DIR"
./node_modules/.bin/playwright test tests/rrd-proxy.spec.ts
