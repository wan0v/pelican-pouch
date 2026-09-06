#!/bin/sh
#
# Pelican Pouch Agent
#
# Polls the panel for the desired Caddy configuration and applies it through the
# local Caddy admin API. All configuration logic lives in the panel; this script
# only transports and applies it.
#
set -eu

# Everything this agent writes is either a credential-adjacent state file or a
# private key Caddy manages, so nothing needs to be group or world readable.
umask 077

: "${POUCH_MODE:=standalone}"
: "${POUCH_HTTP_PORT:=80}"
: "${POUCH_HTTPS_PORT:=443}"
: "${POUCH_BIND:=}"
: "${POUCH_TRUSTED_PROXIES:=}"
: "${POUCH_INTERVAL:=15}"
: "${POUCH_WINGS_UPSTREAM:=}"
: "${POUCH_PANEL_URL:=}"
: "${POUCH_TOKEN_ID:=}"
: "${POUCH_TOKEN:=}"
: "${POUCH_TOKEN_FILE:=}"
: "${POUCH_CA_CERT:=}"
: "${POUCH_ALLOW_HTTP:=false}"
# Caddy's admin API listens on this socket inside the container. It is a socket
# and not a loopback port because the agent is documented to run with
# `network_mode: host`, where 127.0.0.1 is the *host's* loopback and every local
# process could reconfigure Caddy.
: "${POUCH_ADMIN_SOCKET:=/run/pouch/admin.sock}"
# Optional TCP override, e.g. http://127.0.0.1:2019 — only for debugging.
: "${POUCH_ADMIN:=}"
: "${POUCH_AGENT_VERSION:=dev}"
: "${POUCH_DATA_DIR:=/data}"

if [ -n "$POUCH_ADMIN" ]; then
    ADMIN_URL="${POUCH_ADMIN%/}"
    ADMIN_LISTEN="$(printf '%s' "$ADMIN_URL" | sed -e 's#^https\?://##')"
else
    ADMIN_URL='http://localhost'
    ADMIN_LISTEN="unix/${POUCH_ADMIN_SOCKET}"
fi

STATE_DIR="${POUCH_DATA_DIR}/pouch-agent"
RESPONSE="${STATE_DIR}/response.json"
DESIRED="${STATE_DIR}/desired.json"
PAYLOAD="${STATE_DIR}/payload.json"
APPLIED_HASH_FILE="${STATE_DIR}/applied_hash"
BOOTSTRAP="${STATE_DIR}/bootstrap.json"

LAST_ERROR=''
CADDY_PID=''

log() {
    printf '%s [pouch-agent] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >&2
}

# curl against Caddy's admin API. Wrapped so the socket flag stays a single
# argument instead of an unquoted variable that has to word-split.
admin_curl() {
    if [ -n "$POUCH_ADMIN" ]; then
        curl "$@"
    else
        curl --unix-socket "$POUCH_ADMIN_SOCKET" "$@"
    fi
}

# curl against the panel, with the optional private CA.
panel_curl() {
    if [ -n "$POUCH_CA_CERT" ]; then
        curl --cacert "$POUCH_CA_CERT" "$@"
    else
        curl "$@"
    fi
}

fail() {
    log "FATAL: $*"
    exit 1
}

# ---------------------------------------------------------------------------
# Credentials
# ---------------------------------------------------------------------------

# The agent has its own credential, issued on the node's Pouch tab. It never
# reads the Wings configuration: that token unlocks the node's entire remote API
# and signs its JWTs, which is far more than this endpoint needs.
#
# Re-read on every cycle so a rotated token file is picked up without a restart.
load_credentials() {
    PANEL_URL="${POUCH_PANEL_URL%/}"
    TOKEN_ID="$POUCH_TOKEN_ID"
    TOKEN="$POUCH_TOKEN"

    # A token file keeps the secret out of `docker inspect` and /proc/*/environ.
    # It holds either the full `<token_id>.<token>` or just the secret half.
    if [ -n "$POUCH_TOKEN_FILE" ]; then
        if [ ! -r "$POUCH_TOKEN_FILE" ]; then
            LAST_ERROR="token file ${POUCH_TOKEN_FILE} is not readable"

            return 1
        fi

        credential="$(tr -d '\r\n[:space:]' <"$POUCH_TOKEN_FILE")"

        case "$credential" in
        *.*)
            TOKEN_ID="${credential%%.*}"
            TOKEN="${credential#*.}"
            ;;
        *)
            TOKEN="$credential"
            ;;
        esac
    fi

    if [ -z "$PANEL_URL" ] || [ -z "$TOKEN_ID" ] || [ -z "$TOKEN" ]; then
        LAST_ERROR='missing credentials; set POUCH_PANEL_URL, POUCH_TOKEN_ID and POUCH_TOKEN (or POUCH_TOKEN_FILE)'

        return 1
    fi

    return 0
}

# ---------------------------------------------------------------------------
# Caddy
# ---------------------------------------------------------------------------

start_caddy() {
    mkdir -p "$STATE_DIR"
    mkdir -p "$(dirname "$POUCH_ADMIN_SOCKET")"

    # A freshly started Caddy always begins with the empty bootstrap config, so
    # whatever we applied to a previous process is meaningless. Forgetting it
    # here guarantees the first sync after a (re)start always applies the
    # configuration instead of being skipped by the hash comparison.
    rm -f "$APPLIED_HASH_FILE"

    # Boot with an empty configuration. Everything else arrives from the panel.
    printf '{"admin":{"listen":"%s"}}\n' "$ADMIN_LISTEN" >"$BOOTSTRAP"

    caddy run --config "$BOOTSTRAP" &
    CADDY_PID=$!

    # Wait for the admin API to answer before the first sync.
    i=0
    while [ "$i" -lt 30 ]; do
        if admin_curl -fsS "${ADMIN_URL}/config/" >/dev/null 2>&1; then
            log "caddy admin api ready (pid ${CADDY_PID})"
            return 0
        fi

        if ! kill -0 "$CADDY_PID" 2>/dev/null; then
            fail 'caddy exited during startup'
        fi

        i=$((i + 1))
        sleep 1
    done

    fail 'caddy admin api did not become ready'
}

caddy_version() {
    caddy version 2>/dev/null | head -n 1 | awk '{print $1}'
}

# Hostnames that currently have a certificate on disk. Used purely for display
# in the panel so an admin can see whether ACME succeeded.
cert_status_json() {
    certs="${POUCH_DATA_DIR}/caddy/certificates"

    if [ ! -d "$certs" ]; then
        printf '{}'
        return 0
    fi

    find "$certs" -type f -name '*.crt' 2>/dev/null |
        sed -e 's#.*/##' -e 's#\.crt$##' |
        sort -u |
        jq -R . |
        jq -s 'map({key: ., value: "ready"}) | from_entries'
}

apply_config() {
    if admin_curl -fsS -X POST "${ADMIN_URL}/load" \
        -H 'Content-Type: application/json' \
        --data-binary "@${DESIRED}" >/dev/null 2>"${STATE_DIR}/load.err"; then
        return 0
    fi

    LAST_ERROR="caddy load failed: $(tr -d '\n' <"${STATE_DIR}/load.err" | cut -c1-500)"

    return 1
}

# ---------------------------------------------------------------------------
# Sync
# ---------------------------------------------------------------------------

build_payload() {
    applied_hash=''
    [ -f "$APPLIED_HASH_FILE" ] && applied_hash="$(cat "$APPLIED_HASH_FILE")"

    jq -n \
        --arg mode "$POUCH_MODE" \
        --argjson http_port "$POUCH_HTTP_PORT" \
        --argjson https_port "$POUCH_HTTPS_PORT" \
        --arg bind "$POUCH_BIND" \
        --arg trusted_proxies "$POUCH_TRUSTED_PROXIES" \
        --arg wings_upstream "$POUCH_WINGS_UPSTREAM" \
        --arg agent_version "$POUCH_AGENT_VERSION" \
        --arg caddy_version "$(caddy_version)" \
        --arg applied_hash "$applied_hash" \
        --arg last_error "$LAST_ERROR" \
        --argjson cert_status "$(cert_status_json)" \
        '{
            mode: $mode,
            http_port: $http_port,
            https_port: $https_port,
            bind_address: (if $bind == "" then null else $bind end),
            trusted_proxies: (
                if $trusted_proxies == "" then null
                else (
                    $trusted_proxies
                    | split(",")
                    | map(gsub("^\\s+|\\s+$"; ""))
                    | map(select(. != ""))
                )
                end
            ),
            wings_upstream: (if $wings_upstream == "" then null else $wings_upstream end),
            agent_version: $agent_version,
            caddy_version: (if $caddy_version == "" then null else $caddy_version end),
            applied_hash: (if $applied_hash == "" then null else $applied_hash end),
            last_error: (if $last_error == "" then null else $last_error end),
            cert_status: $cert_status
        }' >"$PAYLOAD"
}

sync_once() {
    if ! load_credentials; then
        log "$LAST_ERROR"

        return 1
    fi

    build_payload

    status="$(
        panel_curl -sS -o "$RESPONSE" -w '%{http_code}' \
            -X POST "${PANEL_URL}/api/remote/pouch/sync" \
            -H "Authorization: Bearer ${TOKEN_ID}.${TOKEN}" \
            -H 'Accept: application/json' \
            -H 'Content-Type: application/json' \
            -H "User-Agent: Pelican Pouch Agent/${POUCH_AGENT_VERSION}" \
            --max-time 30 \
            --data-binary "@${PAYLOAD}" 2>"${STATE_DIR}/sync.err"
    )" || status='000'

    if [ "$status" != '200' ]; then
        detail="$(jq -r '.error // .message // empty' "$RESPONSE" 2>/dev/null || true)"
        [ -z "$detail" ] && detail="$(tr -d '\n' <"${STATE_DIR}/sync.err" | cut -c1-300)"
        LAST_ERROR="panel sync failed (http ${status}) ${detail}"
        log "$LAST_ERROR"
        return 1
    fi

    hash="$(jq -r '.hash // empty' "$RESPONSE")"

    if [ -z "$hash" ]; then
        LAST_ERROR='panel response contained no hash'
        log "$LAST_ERROR"
        return 1
    fi

    if [ -f "$APPLIED_HASH_FILE" ] && [ "$hash" = "$(cat "$APPLIED_HASH_FILE")" ]; then
        LAST_ERROR=''
        return 0
    fi

    jq '.config' "$RESPONSE" >"$DESIRED"

    log "applying configuration ${hash}"

    if apply_config; then
        printf '%s' "$hash" >"$APPLIED_HASH_FILE"
        LAST_ERROR=''
        log 'configuration applied'
        return 0
    fi

    log "$LAST_ERROR"

    return 1
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

shutdown() {
    log 'shutting down'

    if [ -n "$CADDY_PID" ]; then
        kill "$CADDY_PID" 2>/dev/null || true
    fi

    exit 0
}

trap shutdown INT TERM

case "$POUCH_MODE" in
standalone | frontend | behind) ;;
*) fail "invalid POUCH_MODE '${POUCH_MODE}' (expected: standalone, frontend or behind)" ;;
esac

# While the agent terminates TLS it has to own 80/443 on every address of the
# node, so the panel ignores a bind address outside of `behind` mode.
if [ -n "$POUCH_BIND" ] && [ "$POUCH_MODE" != 'behind' ]; then
    log "WARNING: POUCH_BIND is only used in behind mode and is ignored in ${POUCH_MODE} mode"
fi

if [ -n "$POUCH_TRUSTED_PROXIES" ] && [ "$POUCH_MODE" != 'behind' ]; then
    log "WARNING: POUCH_TRUSTED_PROXIES is only used in behind mode and is ignored in ${POUCH_MODE} mode"
fi

command -v jq >/dev/null 2>&1 || fail 'jq is required'
command -v curl >/dev/null 2>&1 || fail 'curl is required'

[ -n "$POUCH_PANEL_URL" ] || fail 'POUCH_PANEL_URL is required'

# The panel URL carries the credential and returns a configuration that is
# applied verbatim, so an unverified channel gives away both.
case "${POUCH_PANEL_URL}" in
https://*) ;;
http://*)
    [ "$POUCH_ALLOW_HTTP" = 'true' ] ||
        fail 'refusing to send the agent token over plain HTTP; use https, or set POUCH_ALLOW_HTTP=true for local development'
    log 'WARNING: talking to the panel over plain HTTP - credential and configuration are unprotected'
    ;;
*) fail "POUCH_PANEL_URL must start with https:// (got '${POUCH_PANEL_URL}')" ;;
esac

if [ -n "$POUCH_CA_CERT" ]; then
    [ -r "$POUCH_CA_CERT" ] || fail "POUCH_CA_CERT ${POUCH_CA_CERT} is not readable"
    log "verifying the panel certificate against ${POUCH_CA_CERT}"
fi

mkdir -p "$STATE_DIR"

log "starting agent ${POUCH_AGENT_VERSION} in ${POUCH_MODE} mode (interval ${POUCH_INTERVAL}s)"

start_caddy

while true; do
    if ! kill -0 "$CADDY_PID" 2>/dev/null; then
        fail 'caddy died'
    fi

    sync_once || true

    sleep "$POUCH_INTERVAL" &
    wait $! 2>/dev/null || true
done
