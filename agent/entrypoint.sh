#!/bin/sh
#
# Pelican Pouch Agent
#
# Polls the panel for the desired Caddy configuration and applies it through the
# local Caddy admin API. All configuration logic lives in the panel; this script
# only transports and applies it.
#
set -eu

: "${POUCH_MODE:=standalone}"
: "${POUCH_HTTP_PORT:=80}"
: "${POUCH_HTTPS_PORT:=443}"
: "${POUCH_INTERVAL:=15}"
: "${POUCH_WINGS_UPSTREAM:=}"
: "${POUCH_WINGS_CONFIG:=/etc/pelican/config.yml}"
: "${POUCH_PANEL_URL:=}"
: "${POUCH_TOKEN_ID:=}"
: "${POUCH_TOKEN:=}"
: "${POUCH_ADMIN:=http://127.0.0.1:2019}"
: "${POUCH_INSECURE:=false}"
: "${POUCH_AGENT_VERSION:=dev}"
: "${POUCH_DATA_DIR:=/data}"

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

fail() {
    log "FATAL: $*"
    exit 1
}

# ---------------------------------------------------------------------------
# Credentials
# ---------------------------------------------------------------------------

# Read a top-level scalar from the Wings config.yml. The panel writes that file
# flat (uuid, token_id, token, remote), so a plain sed is sufficient and avoids
# pulling in a YAML parser.
wings_value() {
    [ -r "$POUCH_WINGS_CONFIG" ] || return 0

    sed -n "s/^$1:[[:space:]]*//p" "$POUCH_WINGS_CONFIG" 2>/dev/null |
        head -n 1 |
        sed -e "s/^['\"]//" -e "s/['\"][[:space:]]*$//" -e 's/[[:space:]]*$//'
}

load_credentials() {
    if [ -z "$POUCH_PANEL_URL" ]; then
        PANEL_URL="$(wings_value remote)"
    else
        PANEL_URL="$POUCH_PANEL_URL"
    fi

    if [ -z "$POUCH_TOKEN_ID" ]; then
        TOKEN_ID="$(wings_value token_id)"
    else
        TOKEN_ID="$POUCH_TOKEN_ID"
    fi

    if [ -z "$POUCH_TOKEN" ]; then
        TOKEN="$(wings_value token)"
    else
        TOKEN="$POUCH_TOKEN"
    fi

    # Strip a trailing slash so the URL join below stays predictable.
    PANEL_URL="${PANEL_URL%/}"
}

# ---------------------------------------------------------------------------
# Caddy
# ---------------------------------------------------------------------------

start_caddy() {
    mkdir -p "$STATE_DIR"

    # A freshly started Caddy always begins with the empty bootstrap config, so
    # whatever we applied to a previous process is meaningless. Forgetting it
    # here guarantees the first sync after a (re)start always applies the
    # configuration instead of being skipped by the hash comparison.
    rm -f "$APPLIED_HASH_FILE"

    # Boot with an empty configuration. Everything else arrives from the panel.
    printf '{"admin":{"listen":"127.0.0.1:2019"}}\n' >"$BOOTSTRAP"

    caddy run --config "$BOOTSTRAP" &
    CADDY_PID=$!

    # Wait for the admin API to answer before the first sync.
    i=0
    while [ "$i" -lt 30 ]; do
        if curl -fsS "${POUCH_ADMIN}/config/" >/dev/null 2>&1; then
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
    if curl -fsS -X POST "${POUCH_ADMIN}/load" \
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
            wings_upstream: (if $wings_upstream == "" then null else $wings_upstream end),
            agent_version: $agent_version,
            caddy_version: (if $caddy_version == "" then null else $caddy_version end),
            applied_hash: (if $applied_hash == "" then null else $applied_hash end),
            last_error: (if $last_error == "" then null else $last_error end),
            cert_status: $cert_status
        }' >"$PAYLOAD"
}

sync_once() {
    load_credentials

    if [ -z "$PANEL_URL" ] || [ -z "$TOKEN_ID" ] || [ -z "$TOKEN" ]; then
        LAST_ERROR="missing credentials; mount ${POUCH_WINGS_CONFIG} read-only or set POUCH_PANEL_URL/POUCH_TOKEN_ID/POUCH_TOKEN"
        log "$LAST_ERROR"
        return 1
    fi

    build_payload

    insecure=''
    [ "$POUCH_INSECURE" = 'true' ] && insecure='--insecure'

    status="$(
        curl -sS -o "$RESPONSE" -w '%{http_code}' \
            ${insecure:+$insecure} \
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

command -v jq >/dev/null 2>&1 || fail 'jq is required'
command -v curl >/dev/null 2>&1 || fail 'curl is required'

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
