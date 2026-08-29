# AGENTS.md — pouch plugin

A Pelican Panel plugin (`Wan0v\Pouch`) that publishes server allocations as HTTPS
vhosts via a Caddy instance running on the node. Read `README.md` for the feature story and
`agent/README.md` for the node-side agent.

## Where commands run

**Most tooling runs from the panel root** (`../..`), not from this directory. This plugin
has its own git repository (`wan0v/pelican-pouch`) but no `composer.json`: it resolves
`App\*` and larastan from the panel it is installed into, so it can only be linted and
analysed inside a panel checkout at `plugins/pouch`.

```bash
# from the panel root
vendor/bin/pint plugins/pouch --test             # --test to check only; passes today
vendor/bin/phpstan analyse -c plugins/pouch/phpstan.neon --memory-limit=-1
php artisan p:pouch:preview <node id|name> [--mode=standalone|frontend|behind]
php artisan p:plugin:list                        # confirm the plugin is enabled/loaded
php artisan migrate                              # picks up database/migrations automatically

# from this directory
php scripts/build-zip.php [version]              # writes dist/pouch-<version>.zip
```

- The panel's own `phpstan.neon` has `paths: [app]`, so it never sees plugin code. This
  plugin ships its own `phpstan.neon` (level 6, same `ForbiddenGlobalFunctionsRule`) plus a
  `phpstan-baseline.neon` holding **10 pre-existing** `forbiddenGlobalFunctions` errors:
  core bans the global `app()` / `resolve()` helpers, but the static Filament schema classes
  (`PouchNodeTab`, `PouchRoutesRelationManager`) have no DI entry point. The baseline exists
  so new violations still fail. Don't widen it to silence new code — and don't add
  `ignoreErrors` for them either.
- `PANEL_PLUGIN_DEV_MODE=true` in the panel `.env` makes plugin load failures throw instead
  of being silently reported. Set it first when the plugin mysteriously stops loading.

## Releasing

The version lives in exactly one place: the git tag. Pushing `v1.2.3` triggers
`.github/workflows/release.yml`, which

1. builds and pushes `ghcr.io/wan0v/pouch-agent:1.2.3` and `:latest` from `agent/`,
2. runs `scripts/build-zip.php 1.2.3` and attaches `pouch-1.2.3.zip` to the release,
3. rewrites `update.json` on `main`, which `plugin.json.update_url` points at.

`scripts/build-zip.php` strips `meta` from `plugin.json` (local installation state) and
excludes `agent/`, `AGENTS.md`, `plugin-development.md`, `scripts/`, `.github/`,
`phpstan*.neon` and `update.json`. **`agent/` is deliberately not in the zip** — the agent
ships as a container image, so the panel links `PouchPlugin::AGENT_DOCS_URL` instead of a
local path. If you move that documentation, update the constant.

## Testing reality

There is **no automated test suite for this plugin and there cannot be one in the panel's
Pest suite**: `PluginService::loadPlugins()` and `loadPanelPlugins()` return early under
`runningUnitTests()`, so providers, config, translations and migrations are never
registered during `vendor/bin/pest`.

Verify changes with `p:pouch:preview` (prints the exact Caddy JSON + hash the agent
would receive) and `php artisan tinker`. The dev sqlite DB (`database/database.sqlite`) has
nodes and routes; note that node `localnode` uses an IP FQDN and is therefore unsupported
by design — pick a node with a real domain when previewing.

## Plugin auto-discovery rules (easy to get wrong)

- `src/` is the app dir (not `app/`); PSR-4 `Wan0v\Pouch\` → `src/`, registered at
  runtime by `PluginService`.
- Config **must** be `config/pouch.php` (filename = plugin id) and is loaded via
  `config()->set('pouch', ...)` — so keys are `config('pouch.agent.interval')`.
- Translations are namespaced by plugin id: `trans('pouch::strings....')`.
  `lang/en/strings.php` and `lang/de/strings.php` must stay structurally identical.
- Everything under `src/Providers`, `src/Console/Commands` and `database/migrations` is
  auto-registered. New files in those dirs need no wiring.
- The `Plugin` model is Sushi-backed off the `plugins/` directory, so `plugin.json` edits
  take effect immediately; `meta.status` in that file drives whether the plugin loads.
- New env vars must be prefixed `POUCH_` and written through
  `PouchPlugin::saveSettings()` (`EnvironmentWriterTrait`).

## Invariants — do not break these

- **The proxy base domain has exactly one source of truth:**
  `HostnameService::resolveBaseDomain()` (static, so the `PouchRoute::hostname`
  accessor can use it without the banned `app()` helper). Never re-derive a base domain
  from `$node->fqdn` anywhere else — that bug existed once already. Only the left-most
  `label` is persisted; `hostname`/`url` stay computed accessors.
- The base domain is `Str::lower($node->fqdn)` and **not configurable**, with one
  exception: a node whose FQDN is an IP (`!HostnameService::fqdnIsUsable()`) may carry an
  explicit `pouch_node_settings.proxy_domain`, set on the node's Pouch tab.
  It is *ignored* for nodes with a usable domain FQDN, must be unique across nodes (label
  uniqueness is only scoped per node), and cannot be removed while routes exist. A global
  or per-route base-domain setting still contradicts the design.
- Nodes without a usable FQDN *and* without a proxy domain cannot host routes; guard with
  `HostnameService::supportsNode()` / `guardNode()`. The sync endpoint answers HTTP 409.
- `CaddyConfigService::wingsRoute()` must match the **real FQDN** (`wingsHost()`), never the
  proxy domain — and is skipped entirely when the FQDN is an IP, since a bare IP in the host
  matcher would make Caddy attempt an ACME order it cannot fulfil.
- `PouchNodeSetting::domainFor()` is per-request cached because the base domain is
  resolved once per route while building the config. Query-builder writes to that table must
  call `flushCache()`.
- **No core panel file is modified.** Extension happens only through:
  `Model::resolveRelationUsing` (dynamic `pouchRoutes` / `pouchRoute`
  relations), `ServerResource::registerCustomRelations`, `EditNode::registerCustomTabs`,
  `AllocationResource::modifyTable`, `Role::registerCustomDefaultPermissions`, and
  `Gate::policy`. Keep it that way.
- Because relations are dynamic, read them with
  `$allocation->getRelationValue('pouchRoute')`, not the magic property.
- `PouchNodeTab` and other schemas are built during **service provider registration**,
  before the translator is bound — every `trans()` there must be wrapped in a closure
  (`->label(fn () => trans(...))`).
- `HostnameService::proxyDomainRules()` / `labelRules()` go into Filament only as
  `->rules(fn () => ...)`. A bare array makes Filament `evaluate()` every element, and the
  Laravel closure rules (`function (string $attribute, ...)`) then die with a
  `BindingResolutionException` on `$attribute` — at submit time, where nothing catches it.
  Both fields normalise via `mutateStateForValidationUsing()`; keep that in sync with
  `dehydrateStateUsing()`, or values validate normalised and persist raw.
- Core detach actions release allocations with query-builder updates, bypassing model events.
  Cleanup therefore needs *both* `AllocationObserver::updated()` **and** the `stale` scope /
  `PouchRoute::pruneStaleForNode()`, which runs on every agent sync. Any new cleanup
  path must cover both.
- `CaddyConfigService` is the only place that knows Caddy's config schema; the agent is
  intentionally dumb and applies whatever it gets. Route ordering is `orderBy('label')` to
  keep the hash stable — non-deterministic output makes every agent poll reload Caddy.

## Agent contract

- Single endpoint `POST /api/remote/pouch/sync` (`PouchRouteProvider`),
  mounted on the panel's existing `daemon` middleware, so the agent authenticates with the
  Wings token already on the node (`Bearer <token_id>.<token>` from `/etc/pelican/config.yml`).
  Never introduce a separate secret.
- The request is heartbeat + config poll in one: it writes `PouchNodeState` and
  returns `{hash, generated_at, base_domain, poll_interval, config}`.
- `ProxyMode` (`standalone` / `frontend` / `behind`) is reported by the agent via `POUCH_MODE`
  and shapes the config through `terminatesTls()` and `needsWingsPassthrough()`.
- `agent/entrypoint.sh` is POSIX `sh` under `set -eu` on `caddy:2-alpine` with only
  `curl` + `jq` available — no bash-isms, no extra tooling.
