# Pouch

Publish existing HTTP(S) capable server allocations as HTTPS web services through
a Caddy reverse proxy running on the node.

```text
Wings FQDN of the node   node1.test.de
Allocation / backend     10.10.10.2:5555
Generated hostname       chat-a1b2c3.node1.test.de
Publicly reachable at    https://chat-a1b2c3.node1.test.de
Caddy forwards to        http://10.10.10.2:5555
```

Caddy terminates TLS, the backend receives plain HTTP by default, and WebSocket
connections are upgraded automatically.

## The base domain is derived, not configured

There is deliberately **no global setting** for the proxy base domain — not in
the plugin settings and not per route. It is derived from the node's existing
Wings FQDN:

```text
Wings FQDN   node1.test.de
Wildcard     *.node1.test.de
Hostnames    <label>.node1.test.de
```

Only the left-most label is stored (`pouch_routes.label`); the hostname
is computed at read time by `HostnameService::resolveBaseDomain()`.

### Nodes with an IP address as FQDN

A bare IP has no domain to create subdomains under. For those nodes — and only
those — an explicit **proxy domain** can be stored on the node's *Pouch*
tab and is then used as the base domain:

```text
Wings FQDN     203.0.113.10          (unusable)
Proxy domain   proxy.example.com     (configured once per node)
Hostnames      <label>.proxy.example.com
```

It lives in `pouch_node_settings.proxy_domain`, must be unique across
nodes, and is ignored for nodes that do have a usable domain FQDN — for those the
base domain remains immutable. While routes are published, the domain can be
changed (all hostnames move, certificates are re-issued) but not removed.

## How it works

Wings has no API for managing a web server, so the panel cannot push Caddy
configuration through it. Instead this plugin ships a small **agent** that runs
on the node next to Wings:

```text
┌────────┐   POST /api/remote/pouch/sync    ┌─────────────────────────┐
│ Panel  │ <──────────────────────────────────────  │ Agent (node)            │
│        │   { agent state }                        │  ├─ poller (curl + jq)  │
│        │  ──────────────────────────────────────> │  └─ dedicated Caddy     │
└────────┘   { hash, caddy json config }            └─────────────────────────┘
                                                       POST 127.0.0.1:2019/load
```

- The agent authenticates with the **Wings token that is already on the node**
  (`Bearer <token_id>.<token>`), read from `/etc/pelican/config.yml`. No extra
  secret has to be created or rotated.
- The request doubles as a heartbeat and as a config poll, so the panel always
  knows the agent's mode, version, applied config and certificate state.
- The agent only reloads Caddy when the returned hash differs from what it
  applied last.
- The Caddy instance belongs exclusively to this plugin, so its configuration is
  replaced wholesale. No other web server on the node is touched.

All configuration logic lives in the panel (`CaddyConfigService`); the agent is
intentionally dumb.

## Deployment modes

The mode answers "who owns ports 80 and 443 on this node?" and is set on the
agent via `POUCH_MODE`. The panel adapts the generated configuration accordingly
and warns when the mode contradicts the node's `behind proxy` setting.

| Mode         | Use when                                     | Agent listens        | TLS            |
| ------------ | -------------------------------------------- | -------------------- | -------------- |
| `standalone` | node is not behind a proxy, 80/443 are free   | `:80` + `:443`       | Caddy (ACME)   |
| `frontend`   | node is behind a proxy, agent replaces it     | `:80` + `:443`       | Caddy (ACME)   |
| `behind`     | an existing front-end proxy keeps 80/443      | `127.0.0.1:<port>`   | upstream proxy |

In `frontend` mode the panel additionally emits a passthrough route for the
node's own Wings vhost (`node1.test.de` → `http://127.0.0.1:<daemon_listen>`), so
taking over the front-end role does not break Wings. The matcher is always the
real FQDN, never a configured proxy domain; a node with an IP FQDN has no Wings
vhost and therefore gets no passthrough route.

See [the agent documentation](https://github.com/wan0v/pelican-pouch/blob/main/agent/README.md)
for installation. The link is absolute on purpose: `agent/` is not part of the
plugin zip, since the agent ships as a container image.

## Requirements

- A wildcard DNS record `*.<base domain>` pointing at the node. The node's
  *Pouch* tab performs a live check.
- Port 80 reachable from the internet for the ACME HTTP-01 challenge
  (`standalone` / `frontend` mode).
- A node whose FQDN is a domain name — or, if it is an IP address, a proxy
  domain configured on the node's *Pouch* tab.

## Installation

1. Download `pouch-<version>.zip` from the
   [latest release](https://github.com/wan0v/pelican-pouch/releases/latest).
2. In the panel: *Admin* → *Plugins* → import the zip, then install and enable it.
3. Install the agent on every node that should serve routes — the node's *Pouch*
   tab renders a ready-made `compose.yml` for exactly that node.

Later versions are picked up automatically: `plugin.json` points `update_url` at
the repository's `update.json`, so the panel offers the update itself.

## Usage

1. Install the agent on the node (the node's *Pouch* tab renders a
   ready-made, node-specific `compose.yml`).
2. Open a server in the admin area. Below the allocations you will find
   **Pouch Routes**.
3. *Publish via HTTPS* → pick an allocation. A label such as `chat-a1b2c3` is
   suggested and can be edited; the base domain is shown as a fixed suffix.
4. Optionally switch the backend to HTTPS (with an option to skip certificate
   verification for self-signed backends).

The client area shows the resulting URL on the *Networking* page as a read-only,
copyable column. Enabling and disabling routes stays an administrator action.

## Permissions

Registers the standard `viewList / view / create / update / delete` permissions
for the `pouchRoute` model. Access is additionally scoped to the nodes a
role may target, matching core behaviour.

## Settings

Available via the plugin list (*Settings*), stored in `.env`:

| Setting                | `.env` key                          | Purpose                                    |
| ---------------------- | ----------------------------------- | ------------------------------------------ |
| ACME account email     | `POUCH_ACME_EMAIL`           | expiry notifications from the CA           |
| ACME directory URL     | `POUCH_ACME_CA`              | custom CA, e.g. an internal ACME server    |
| Agent poll interval    | `POUCH_AGENT_INTERVAL`       | how often the agent syncs                  |
| Offline threshold      | `POUCH_AGENT_OFFLINE_AFTER`  | when the panel marks an agent offline      |
| Agent image            | `POUCH_AGENT_IMAGE`          | image used in the generated compose file   |

## Consistency

Routes are removed automatically when their allocation is released, deleted or
moved to another server or node:

- an observer covers changes made through the model,
- a `stale` scope plus `pruneStaleForNode()` covers the core detach actions,
  which release allocations with a query builder update and therefore bypass
  model events. This runs on every agent sync, so a released allocation can
  never stay published.

## Debugging

```bash
php artisan p:pouch:preview <node> [--mode=standalone|frontend|behind]
```

Prints the exact Caddy JSON the agent would receive, plus its hash. The same
output is available read-only on the node's *Pouch* tab.

> Note: the panel's PHPUnit suite does not load plugins
> (`PluginService::loadPlugins()` returns early under `runningUnitTests()`), so
> this command is the primary way to verify configuration generation.

## License

MIT — see [LICENSE](LICENSE).
