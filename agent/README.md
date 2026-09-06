# Pelican Pouch Agent

A dedicated [Caddy](https://caddyserver.com) instance plus a ~200 line poller.
It runs **on the node**, asks the panel what its configuration should look like
and applies it through Caddy's local admin API.

Because this Caddy instance is owned exclusively by the plugin, the agent
replaces its configuration wholesale on every change. It never touches any other
web server on the node.

## How it authenticates

The agent has a credential of its own, generated per node in the panel under
*Admin* → *Nodes* → your node → *Pouch* → **Agent credential**. It is shown
exactly once, as a ready `pouch.env`:

```ini
POUCH_PANEL_URL=https://panel.example.com
POUCH_TOKEN_ID=...
POUCH_TOKEN=...
```

Put that file next to the compose file, `chmod 600` it, and the compose file
picks it up through `env_file`. Instead of `POUCH_TOKEN` you can point
`POUCH_TOKEN_FILE` at a file holding `<token_id>.<token>` (or just the secret),
which keeps it out of `docker inspect` and `/proc/*/environ`.

The credential is only valid for `POST /api/remote/pouch/sync`. It deliberately
is **not** the Wings token: that one unlocks the node's whole remote API — every
server configuration including its egg secrets, backup upload URLs, the SFTP
credential check, the activity log — and is the signing key for every node JWT.
A container that terminates TLS on 80/443 for untrusted backends should not hold
it, and with its own token it can be revoked without touching Wings.

Do not mount `/etc/pelican/config.yml` into this container. Older agents read it;
this one does not, and the panel refuses the Wings token outright — an agent
still presenting it gets a 403.

TLS is always verified. For a panel with a private or self-signed certificate,
mount the CA bundle and set `POUCH_CA_CERT` — there is no switch to skip
verification, because that channel carries the credential *and* returns the
configuration the agent applies unverified.

The Caddy admin API listens on the unix socket `/run/pouch/admin.sock` inside the
container. It is not a loopback port on purpose: with `network_mode: host`,
`127.0.0.1:2019` would be the *host's* loopback, where every local process could
push a new configuration into Caddy without authenticating.

## Modes

The mode is the answer to "who owns port 80 and 443 on this node?". Pick it
based on the node's `behind proxy` setting in the panel.

### `standalone`

The node is **not** behind a proxy, ports 80/443 are free.

The agent binds them, terminates TLS and obtains a certificate per published
hostname via ACME (HTTP-01 / TLS-ALPN).

```yaml
POUCH_MODE: standalone
```

### `frontend`

The node **is** behind a proxy today, and you want the agent to take that role
over.

The agent binds 80/443, terminates TLS and additionally proxies the node's own
Wings vhost (`node1.test.de` → `http://127.0.0.1:8080`), which the panel adds to
the configuration automatically. Stop and disable your previous proxy first,
otherwise the ports are taken.

```yaml
POUCH_MODE: frontend
# optional, defaults to 127.0.0.1:<daemon_listen> from the panel
POUCH_WINGS_UPSTREAM: 127.0.0.1:8080
```

### `behind`

An existing front-end proxy keeps ports 80/443.

The agent listens on `<POUCH_BIND>:<POUCH_HTTP_PORT>` and serves **plain HTTP**
only; automatic HTTPS is disabled. Your existing proxy terminates TLS and needs a
wildcard vhost pointing at the agent. The panel shows you the exact snippet on
the node's *Pouch* tab.

```yaml
POUCH_MODE: behind
POUCH_HTTP_PORT: 8080
```

With this mode you cannot use `network_mode: host` blindly if the port collides;
adjust `POUCH_HTTP_PORT` accordingly.

#### Binding another local address

`POUCH_BIND` defaults to `127.0.0.1`, which is what you want when the front-end
proxy runs on the same machine. If it runs on a different host in a private
network (e.g. Wireguard-Tunnel), bind the interface it reaches instead and tell the agent which sources
may set `X-Forwarded-*` headers:

```yaml
POUCH_MODE: behind
POUCH_BIND: 10.0.0.2
POUCH_HTTP_PORT: 8080
POUCH_TRUSTED_PROXIES: 10.0.0.0/24
```

Both are only used in `behind` mode — while the agent terminates TLS it has to
own ports 80/443 on *every* address of the node, otherwise ACME breaks on the
ones left out. The agent logs a warning and the panel ignores them.

Two things to be aware of:

- The agent serves **unencrypted HTTP** on that address. Only bind an interface
  whose network you trust, and firewall the port to the front-end proxy.
- Loopback is always trusted. Every other proxy address has to be listed in
  `POUCH_TRUSTED_PROXIES` (comma separated, plain IPs or CIDR), otherwise Caddy
  ignores the forwarded headers and every backend sees the proxy as the client.
- The panel rejects ranges wider than a `/8` (`/32` for IPv6). Trusting a whole
  continent lets any client in it claim any source address towards the backends
  of this node, so name the network the front-end proxy actually sits in.

## DNS

A wildcard record for the node's Wings FQDN must point at the node:

```text
*.node1.test.de.   A   <node ip>
```

Without it, neither ACME nor the routing will work. The panel's node tab
performs a live check and tells you what it resolved.

## Installation

1. Generate the agent token on the node's *Pouch* tab and save the shown
   `pouch.env` next to the compose file (`chmod 600 pouch.env`).
2. Copy `compose.example.yml` to the node as `compose.yml` (the panel renders a
   ready-made, node-specific version on the same tab).
3. Adjust `POUCH_MODE`.
4. `docker compose up -d`
5. Check the node's *Pouch* tab in the panel — the agent should show up
   as online within a few seconds.

## Environment variables

| Variable               | Default                   | Description                                          |
| ---------------------- | ------------------------- | ---------------------------------------------------- |
| `POUCH_MODE`           | `standalone`              | `standalone`, `frontend` or `behind`                 |
| `POUCH_HTTP_PORT`      | `80`                      | HTTP port / listen port in `behind` mode             |
| `POUCH_HTTPS_PORT`     | `443`                     | HTTPS port, unused in `behind` mode                  |
| `POUCH_BIND`           | `127.0.0.1`               | Address to bind, `behind` mode only                  |
| `POUCH_TRUSTED_PROXIES`| _(loopback)_              | Extra CIDRs trusted for `X-Forwarded-*` headers      |
| `POUCH_INTERVAL`       | `15`                      | Poll interval in seconds                             |
| `POUCH_WINGS_UPSTREAM` | _(from panel)_            | Wings upstream for `frontend` mode, as `host:port`   |
| `POUCH_PANEL_URL`      | **required**              | Panel base URL, `https://` unless `POUCH_ALLOW_HTTP` |
| `POUCH_TOKEN_ID`       | **required**              | Agent token id from the panel                        |
| `POUCH_TOKEN`          | **required**              | Agent token from the panel                           |
| `POUCH_TOKEN_FILE`     | _(unset)_                 | File holding the credential, preferred over the above |
| `POUCH_CA_CERT`        | _(unset)_                 | CA bundle used to verify the panel certificate       |
| `POUCH_ALLOW_HTTP`     | `false`                   | Permit a plain-HTTP panel URL (development only)     |
| `POUCH_ADMIN_SOCKET`   | `/run/pouch/admin.sock`   | Unix socket of Caddy's admin API                     |
| `POUCH_DATA_DIR`       | `/data`                   | Caddy data directory (certificates + agent state)    |

## Troubleshooting

```bash
docker logs -f pelican-pouch
```

- **`missing credentials`** — `pouch.env` is missing or incomplete. Check
  `env_file` in the compose file.
- **`panel sync failed (http 403)`** — the credential does not match the panel.
  Generate a new agent token on the node's *Pouch* tab and replace `pouch.env`.
- **`panel sync failed (http 429)`** — the sync endpoint is rate limited to 120
  requests per minute per node. Raise `POUCH_INTERVAL`.
- **`refusing to send the agent token over plain HTTP`** — `POUCH_PANEL_URL` is
  `http://`. Use https, or set `POUCH_CA_CERT` if the certificate is private.
- **`panel sync failed (http 422)`** — the panel refused a reported value.
  Almost always `POUCH_TRUSTED_PROXIES` (a range wider than /8) or
  `POUCH_WINGS_UPSTREAM` (it has to be a bare `host:port`).
- **`panel sync failed (http 409)`** — the node uses an IP address as its FQDN.
  Hostnames cannot be derived from an IP; give the node a real domain name.
- **`caddy load failed`** — the configuration was rejected. The panel's node tab
  shows the same error under *Last error*.
- **Certificate never becomes ready** — verify the wildcard DNS record and that
  port 80 is reachable from the internet for the ACME HTTP-01 challenge.
