# Pelican Pouch Agent

A dedicated [Caddy](https://caddyserver.com) instance plus a ~200 line poller.
It runs **on the node**, asks the panel what its configuration should look like
and applies it through Caddy's local admin API.

Because this Caddy instance is owned exclusively by the plugin, the agent
replaces its configuration wholesale on every change. It never touches any other
web server on the node.

## How it authenticates

The agent mounts the Wings configuration read-only and reads `remote`,
`token_id` and `token` from it. It then authenticates against the panel with the
exact same `Bearer <token_id>.<token>` scheme Wings uses.

That means:

- no additional secret has to be created, copied or rotated,
- if you reset the node token in the panel, Wings rewrites `config.yml` and the
  agent picks it up on its next cycle.

If `/etc/pelican/config.yml` is not available, set `POUCH_PANEL_URL`, `POUCH_TOKEN_ID`
and `POUCH_TOKEN` manually instead.

The Caddy admin API is bound to `127.0.0.1:2019` inside the container and is
never exposed.

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
network, bind the interface it reaches instead and tell the agent which sources
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

## DNS

A wildcard record for the node's Wings FQDN must point at the node:

```text
*.node1.test.de.   A   <node ip>
```

Without it, neither ACME nor the routing will work. The panel's node tab
performs a live check and tells you what it resolved.

## Installation

1. Copy `compose.example.yml` to the node as `compose.yml` (the panel renders a
   ready-made, node-specific version on the node's *Pouch* tab).
2. Adjust `POUCH_MODE`.
3. `docker compose up -d`
4. Check the node's *Pouch* tab in the panel — the agent should show up
   as online within a few seconds.

## Environment variables

| Variable               | Default                   | Description                                       |
| ---------------------- | ------------------------- | ------------------------------------------------- |
| `POUCH_MODE`           | `standalone`              | `standalone`, `frontend` or `behind`              |
| `POUCH_HTTP_PORT`      | `80`                      | HTTP port / listen port in `behind` mode          |
| `POUCH_HTTPS_PORT`     | `443`                     | HTTPS port, unused in `behind` mode               |
| `POUCH_BIND`           | `127.0.0.1`               | Address to bind, `behind` mode only               |
| `POUCH_TRUSTED_PROXIES`| _(loopback)_              | Extra CIDRs trusted for `X-Forwarded-*` headers   |
| `POUCH_INTERVAL`       | `15`                      | Poll interval in seconds                          |
| `POUCH_WINGS_UPSTREAM` | _(from panel)_            | Wings upstream for `frontend` mode                |
| `POUCH_WINGS_CONFIG`   | `/etc/pelican/config.yml` | Where to read the credentials from                |
| `POUCH_PANEL_URL`      | _(from Wings config)_     | Panel base URL                                    |
| `POUCH_TOKEN_ID`       | _(from Wings config)_     | Node token id                                     |
| `POUCH_TOKEN`          | _(from Wings config)_     | Node token                                        |
| `POUCH_INSECURE`       | `false`                   | Skip TLS verification when talking to the panel   |
| `POUCH_DATA_DIR`       | `/data`                   | Caddy data directory (certificates + agent state) |

## Troubleshooting

```bash
docker logs -f pelican-pouch
```

- **`missing credentials`** — `/etc/pelican/config.yml` is not mounted or not
  readable. Check the volume mount.
- **`panel sync failed (http 401)`** — the token in `config.yml` no longer
  matches the panel. Restart Wings so it refreshes the file.
- **`panel sync failed (http 409)`** — the node uses an IP address as its FQDN.
  Hostnames cannot be derived from an IP; give the node a real domain name.
- **`caddy load failed`** — the configuration was rejected. The panel's node tab
  shows the same error under *Last error*.
- **Certificate never becomes ready** — verify the wildcard DNS record and that
  port 80 is reachable from the internet for the ACME HTTP-01 challenge.
