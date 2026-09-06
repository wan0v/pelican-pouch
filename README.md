# Pouch

Pouch gives a game server, web panel or any other HTTP service running on your
node a real HTTPS address — without you touching a web server, a DNS record per
server, or a certificate.

You pick an allocation, Pouch gives it a hostname:

```text
Wings FQDN of the node   node1.test.de
Allocation / backend     10.10.10.2:5555
Generated hostname       chat-a1b2c3.node1.test.de
Publicly reachable at    https://chat-a1b2c3.node1.test.de
Caddy forwards to        http://10.10.10.2:5555
```

A [Caddy](https://caddyserver.com) instance on the node terminates TLS and gets
the certificate automatically. Your backend keeps receiving plain HTTP (you can
switch it to HTTPS), and WebSocket connections are upgraded for you.

The hostname is built from the node's own Wings FQDN, so there is nothing to
configure globally: every node brings its own base domain with it.

---

## Before you start

Four things have to be true. The node's *Pouch* tab tells you about most of them.

1. **The node's FQDN is a real domain name**, e.g. `node1.test.de`.
   If it is an IP address, hostnames cannot be derived from it — see
   [Nodes with an IP address as FQDN](#nodes-with-an-ip-address-as-fqdn).
2. **A wildcard DNS record points at the node:**

   ```text
   *.node1.test.de.   A   <node ip>
   ```

   Without it nothing resolves and no certificate can be issued. The node's
   *Pouch* tab checks whether the wildcard resolves at all and shows the result
   under *DNS requirement*.
3. **Port 80 is reachable from the internet** so the certificate authority can
   run its HTTP-01 challenge. Only needed in the modes where Pouch issues the
   certificates itself (`standalone` and `frontend`, see step 2).
4. **Docker is installed on the node.** Pouch ships a small helper — the *agent* —
   as a container that runs next to Wings.

---

## Step 1 — Install the plugin

1. Download `pouch-<version>.zip` from the
   [latest release](https://github.com/wan0v/pelican-pouch/releases/latest).
2. In the panel: *Admin* → *Plugins* → **Import**, pick the zip.
3. **Install**, then **Enable** it.

Later versions are offered by the panel itself — the plugin points at its own
update feed, so you get an **Update** button on the plugin list when a new
release is out.

> **Upgrading from a version before the agent credential:** the agent used to
> authenticate with the Wings token it read from `/etc/pelican/config.yml`. That
> is no longer accepted. Right after the update **every node goes offline on the
> Pouch tab** until you generate an agent token there and put the resulting
> `pouch.env` next to the agent's compose file (step 3). Published routes keep
> working in the meantime — the agent's Caddy holds the configuration it applied
> last — but nothing new rolls out until the credential is in place.

---

## Step 2 — Pick a mode

The mode answers one question: **who owns ports 80 and 443 on this node?**

- Nothing else uses them → **`standalone`**
- Something does (nginx, Caddy, Traefik …) and you want Pouch to take over →
  **`frontend`**
- Something does and it should stay → **`behind`**

| Mode         | Use when                                     | Agent listens        | Who issues the certificate |
| ------------ | -------------------------------------------- | -------------------- | -------------------------- |
| `standalone` | node is not behind a proxy, 80/443 are free   | `:80` + `:443`       | Pouch (ACME)               |
| `frontend`   | node is behind a proxy, Pouch replaces it     | `:80` + `:443`       | Pouch (ACME)               |
| `behind`     | an existing front-end proxy keeps 80/443      | `<bind>:<port>`      | your existing proxy        |

In **`frontend`** mode Pouch also keeps your node reachable: it automatically
serves the node's own Wings vhost (`node1.test.de` → Wings) so taking over the
front-end role does not cut Wings off. Stop and disable your previous proxy
first, otherwise the ports are still taken.

In **`behind`** mode Pouch serves plain HTTP on `127.0.0.1` and your existing
proxy terminates TLS. That proxy needs a wildcard vhost — the panel prints a
ready-made snippet for it, see step 3. If the proxy runs on a *different* host,
the agent can bind another local address instead; the
[agent documentation](https://github.com/wan0v/pelican-pouch/blob/main/agent/README.md#binding-another-local-address)
covers that case.

> The panel warns you when the mode does not match the node's *behind proxy*
> setting — e.g. *"This node is configured as 'behind proxy' but the agent runs
> in standalone mode. Ports 80/443 are most likely already taken."*

---

## Step 3 — Install the agent on the node

The panel writes the compose file for you.

1. Open *Admin* → *Nodes* → your node → *Edit* → the **Pouch** tab (last tab).
2. In **Agent credential**, press *Generate agent token*. The panel shows the
   complete `pouch.env` once, and only once — copy it to the node next to the
   compose file and `chmod 600 pouch.env`.
3. Expand **Agent installation**. It contains a complete `compose.yml`, already
   filled in for *this* node — image, mode, ports and poll interval.
4. Copy it to the node as `compose.yml`, adjust `POUCH_MODE` if the suggested
   one is not what you want, and run:

   ```bash
   docker compose up -d
   ```

5. Go back to the **Pouch** tab. Within a few seconds *Agent status* flips to
   **Agent online**.

Two things worth knowing about that generated file:

- **The secret lives beside it, not in it.** `compose.yml` only references
  `./pouch.env`, so it can be copied around and committed without leaking the
  credential.
- **It never disables certificate verification.** If your *panel* uses a
  self-signed or private certificate, mount the CA bundle and point
  `POUCH_CA_CERT` at it. That channel carries the agent token and returns the
  configuration the agent applies unverified, so it is not one to leave open.

If you chose `behind` mode, the same tab grows a **Front-end proxy
configuration** section once the agent has reported in. It contains a ready
Caddyfile block plus a commented-out nginx equivalent — add one of them to your
existing proxy so it forwards the wildcard to the agent.

Full agent reference (all environment variables, mode details, troubleshooting):
[agent/README.md](https://github.com/wan0v/pelican-pouch/blob/main/agent/README.md).

---

## Step 4 — Publish a server

1. Open a server in the admin area. Below the allocations there is a
   **Pouch Routes** section.
2. Click **Publish via HTTPS**.
3. Pick an **Allocation**. A **Hostname label** such as `chat-a1b2c3` is filled
   in for you and can be edited; the base domain is shown next to it as a fixed
   suffix you cannot change.
4. Optionally set **Backend scheme** to HTTPS if your service speaks HTTPS
   itself. A **Skip backend certificate verification** switch appears for
   self-signed backends.
5. Save. The agent applies the change on its next poll (15 seconds by default).

The route table then shows the **Public URL** (clickable and copyable), the
**Backend**, whether the **Certificate** is *In sync* or still *Pending
rollout*, and an **Enabled** toggle to take a route offline without deleting it.

**Your customers see the URL too:** in the client area on the **Network** page
there is a **Web** column with the public URL — read-only and copyable.
Publishing and unpublishing stays an administrator action.

---

## Choosing hostname labels

Only the left-most part of the hostname is yours to pick. Everything after the
first dot comes from the node.

- Lower case letters, digits and hyphens; it has to start and end with a letter
  or digit. Max 63 characters.
- Unique per node — two servers on the same node cannot share a label.
- These labels are reserved and rejected, because they are commonly used for
  other services on the same domain: `www`, `wings`, `daemon`, `panel`, `node`,
  `admin`, `api`, `mail`, `smtp`, `imap`, `ns1`, `ns2`, `localhost`.

The suggested label is built from the allocation's **notes** if it has any,
otherwise from the server name, plus six random characters so it stays unique
and unguessable.

---

## Nodes with an IP address as FQDN

A bare IP has no domain to create subdomains under, so Pouch stays unavailable
on such a node and its *Pouch* tab tells you so:

> This node uses an IP address as its FQDN. Hostnames cannot be derived from an
> IP address, so Pouch stays unavailable until a proxy domain is configured here.

For these nodes — and only these — you can point Pouch at a domain of your own:

```text
Wings FQDN     203.0.113.10          (unusable)
Proxy domain   proxy.example.com     (configured once, on this node)
Hostnames      <label>.proxy.example.com
```

Use **Set proxy domain** on the node's *Pouch* tab. The same wildcard DNS
requirement applies: `*.proxy.example.com` has to resolve to this node.

Things to keep in mind:

- The domain has to be **unique across all nodes**.
- You can **change** it while routes are published — every hostname moves and
  the agent requests fresh certificates on its next sync. The panel warns you
  and tells you how many routes are affected.
- You **cannot remove** it while routes are published. Delete the routes first.
- Nodes that already have a real domain FQDN ignore this setting entirely;
  for them the base domain is fixed.

---

## Settings

*Admin* → *Plugins* → Pouch → *Settings*. Everything is written to your `.env`.

| Setting                            | Default                             | `.env` key                  | Purpose                                             |
| ---------------------------------- | ----------------------------------- | --------------------------- | --------------------------------------------------- |
| ACME account email                 | _(empty)_                           | `POUCH_ACME_EMAIL`          | expiry notifications from the certificate authority  |
| ACME directory URL                 | _(empty)_                           | `POUCH_ACME_CA`             | a custom CA, e.g. an internal ACME server            |
| Agent poll interval (seconds)      | `15`                                | `POUCH_AGENT_INTERVAL`      | how often the agent asks the panel for its config    |
| Mark agent offline after (seconds) | `60`                                | `POUCH_AGENT_OFFLINE_AFTER` | when the panel considers an agent gone               |
| Agent container image              | `ghcr.io/wan0v/pouch-agent:latest`  | `POUCH_AGENT_IMAGE`         | image used in the generated compose file             |

Leave both ACME fields empty to use Caddy's default issuers (Let's Encrypt with
ZeroSSL as fallback). The offline threshold has to be at least twice the poll
interval, otherwise a single missed poll already marks the node offline.

A few knobs live in `config/pouch.php` only, without a UI or env key: the length
of the generated label slug and its random suffix, and the list of reserved
labels.

---

## Permissions

Pouch registers the usual `viewList / view / create / update / delete`
permissions for the `pouchRoute` model, so you can hand route management to a
role without giving away anything else. Access is additionally scoped to the
nodes a role may target, matching core behaviour.

The client area has no write path at all by design — customers can read their
URL, nothing more.

---

## Troubleshooting

**The agent never comes online.**
Check the container on the node: `docker logs -f pelican-pouch`.
`missing credentials` means `pouch.env` is not next to the compose file or is
missing a value. `http 403` means the token does not match the panel — generate
a new one on the *Pouch* tab and replace `pouch.env`.

**"Wildcard DNS does not resolve yet."**
The `*.<base domain>` record is missing, or has not propagated. Note the panel
only checks *that* the wildcard resolves, not that it resolves to this node — so
a stale record pointing elsewhere passes the check but breaks in practice.

**The certificate stays on "Pending rollout".**
Almost always DNS or port 80: the certificate authority has to reach the node on
port 80 for the HTTP-01 challenge. Check your firewall and the wildcard record.

**`http 409` in the agent logs.**
The node uses an IP address as its FQDN and no proxy domain is set. See
[Nodes with an IP address as FQDN](#nodes-with-an-ip-address-as-fqdn).

**The configuration stays on "Pending rollout" and *Last error* is filled.**
The agent rejected the configuration. The message on the *Pouch* tab is the one
Caddy returned.

**A route disappeared on its own.**
That is intentional. When an allocation is released, deleted, or moved to
another server or node, its route goes with it — a hostname must never point at
something that no longer belongs to that server.

**Ports 80/443 are already in use after starting the agent.**
You are in `standalone` or `frontend` mode with another web server still
running. Stop it, or switch to `behind` mode.

More node-side symptoms are covered in the
[agent documentation](https://github.com/wan0v/pelican-pouch/blob/main/agent/README.md#troubleshooting).

---

## How it works

Wings has no API for managing a web server, so the panel cannot push proxy
configuration through it. Instead Pouch runs a small agent on the node:

```text
  Panel                                         Agent (on the node)
  │                                             │
  │       POST /api/remote/pouch/sync           │   every 15 seconds:
  │ <────────────────────────────────────────── │   "my mode, ports, versions
  │       { mode, ports, versions, hash }       │    and the hash I applied"
  │                                             │
  │       { hash, base domain, caddy config }   │
  │ ──────────────────────────────────────────> │   reloads Caddy — but only
  │                                             │   if the hash changed
```

- **One request does everything.** It is heartbeat and config poll in one, so
  the panel always knows the agent's mode, version, applied configuration and
  certificate state.
- **Its own credential.** The agent authenticates with a token issued per node
  on the *Pouch* tab (`Bearer <token_id>.<token>`), which is valid for this sync
  endpoint and nothing else. The Wings token stays on the node: it unlocks the
  node's entire remote API — every server configuration including its egg
  secrets, backup upload URLs, the SFTP credential check — and signs every node
  JWT, which is far more than a reverse proxy needs.
- **Reloads only on change.** The panel returns a hash alongside the
  configuration; the agent reloads Caddy only when it differs from what it
  applied last.
- **A Caddy instance of its own.** It belongs exclusively to the plugin, so its
  configuration is replaced wholesale. No other web server on the node is
  touched.
- **All the thinking happens in the panel.** The agent applies whatever it is
  given and knows nothing about hostnames, routes or certificates.

**Why the base domain is derived and not configured.** Every node already has a
name that resolves to it — its Wings FQDN. Deriving hostnames from it means
there is exactly one place a base domain can come from, it can never drift out
of sync with the node, and multi-node setups work without any per-node
configuration. Only nodes whose FQDN is an IP address break that assumption,
which is why they — and only they — get an explicit proxy domain.

**No core panel file is modified.** Pouch extends the panel exclusively through
its official extension points.

---

## License

MIT — see [LICENSE](LICENSE).
