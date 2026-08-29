<?php

namespace Wan0v\Pouch\Filament\Admin\Schemas;

use App\Enums\TablerIcon;
use App\Models\Node;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Phiki\Grammar\Grammar;
use Wan0v\Pouch\Enums\ProxyMode;
use Wan0v\Pouch\Models\PouchNodeSetting;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Models\PouchRoute;
use Wan0v\Pouch\PouchPlugin;
use Wan0v\Pouch\Services\AgentSnippetService;
use Wan0v\Pouch\Services\CaddyConfigService;
use Wan0v\Pouch\Services\HostnameService;

/**
 * The node's "Pouch" tab.
 *
 * This is built while service providers are being registered, so every
 * translated string has to be wrapped in a closure — the translator is not
 * bound yet at that point.
 */
class PouchNodeTab
{
    public static function make(): Tab
    {
        return Tab::make('pouch')
            ->label(fn () => trans('pouch::strings.node.tab'))
            ->icon(TablerIcon::World)
            // Without this the tab inherits the grid the node's Tabs container
            // declares (up to four columns), which squeezes the sections into
            // narrow side-by-side columns instead of stacking them.
            ->columns(1)
            ->schema([
                Section::make()
                    ->heading(fn () => trans('pouch::strings.node.agent_status'))
                    ->icon(TablerIcon::Heartbeat)
                    ->columns(3)
                    ->visible(fn (Node $node) => self::supported($node))
                    ->schema([
                        TextEntry::make('pouch_base_domain')
                            ->label(fn () => trans('pouch::strings.node.base_domain'))
                            ->helperText(fn () => trans('pouch::strings.hints.base_domain'))
                            ->copyable()
                            ->state(fn (Node $node) => app(HostnameService::class)->wildcard($node)),

                        TextEntry::make('pouch_agent_status')
                            ->label(fn () => trans('pouch::strings.node.agent_status'))
                            ->badge()
                            ->state(fn (Node $node) => self::state($node)?->is_online
                                ? trans('pouch::strings.node.agent_online')
                                : trans('pouch::strings.node.agent_offline'))
                            ->color(fn (Node $node) => self::state($node)?->is_online ? 'success' : 'danger'),

                        TextEntry::make('pouch_last_seen')
                            ->label(fn () => trans('pouch::strings.node.last_seen'))
                            ->placeholder(fn () => trans('pouch::strings.node.agent_never'))
                            ->state(fn (Node $node) => self::state($node)?->last_seen_at?->diffForHumans()),

                        TextEntry::make('pouch_mode')
                            ->label(fn () => trans('pouch::strings.node.mode'))
                            ->badge()
                            ->placeholder('—')
                            ->state(fn (Node $node) => self::state($node)?->mode)
                            ->helperText(fn (Node $node) => self::state($node)?->mode?->getDescription()),

                        // Only meaningful while TLS is terminated upstream; the
                        // other modes always bind every address of the node.
                        TextEntry::make('pouch_listen')
                            ->label(fn () => trans('pouch::strings.node.listen'))
                            ->helperText(fn () => trans('pouch::strings.hints.listen'))
                            ->copyable()
                            ->visible(fn (Node $node) => self::listenAddress($node) !== null)
                            ->state(fn (Node $node) => self::listenAddress($node)),

                        TextEntry::make('pouch_versions')
                            ->label(fn () => trans('pouch::strings.node.caddy_version'))
                            ->placeholder('—')
                            ->state(function (Node $node) {
                                $state = self::state($node);

                                if (!$state?->caddy_version) {
                                    return null;
                                }

                                return $state->caddy_version . ' (' . trans('pouch::strings.node.agent_version') . ' ' . ($state->agent_version ?? '—') . ')';
                            }),

                        TextEntry::make('pouch_sync_state')
                            ->label(fn () => trans('pouch::strings.node.sync_state'))
                            ->badge()
                            ->state(fn (Node $node) => self::inSync($node)
                                ? trans('pouch::strings.node.in_sync')
                                : trans('pouch::strings.node.pending'))
                            ->color(fn (Node $node) => self::inSync($node) ? 'success' : 'warning'),

                        TextEntry::make('pouch_routes_count')
                            ->label(fn () => trans('pouch::strings.node.routes_count'))
                            ->state(fn (Node $node) => PouchRoute::query()
                                ->where('node_id', $node->id)
                                ->enabled()
                                ->count()),

                        TextEntry::make('pouch_dns')
                            ->label(fn () => trans('pouch::strings.node.dns'))
                            ->badge()
                            ->state(fn (Node $node) => self::dnsMessage($node))
                            ->color(fn (Node $node) => self::resolved($node) !== null ? 'success' : 'warning')
                            ->helperText(fn (Node $node) => trans('pouch::strings.node.dns_hint', [
                                'wildcard' => app(HostnameService::class)->wildcard($node),
                            ])),

                        TextEntry::make('pouch_last_error')
                            ->label(fn () => trans('pouch::strings.node.last_error'))
                            ->columnSpanFull()
                            ->color('danger')
                            ->visible(fn (Node $node) => filled(self::state($node)?->last_error))
                            ->state(fn (Node $node) => self::state($node)?->last_error),

                        TextEntry::make('pouch_bind_untrusted_warning')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->color('warning')
                            ->icon(TablerIcon::AlertTriangle)
                            ->visible(fn (Node $node) => self::bindUntrusted($node))
                            ->state(fn (Node $node) => trans('pouch::strings.node.bind_untrusted_warning', [
                                'bind' => self::state($node)?->bind_address,
                            ])),

                        TextEntry::make('pouch_behind_proxy_warning')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->color('warning')
                            ->icon(TablerIcon::AlertTriangle)
                            ->visible(fn (Node $node) => $node->behind_proxy && self::state($node)?->mode === ProxyMode::Standalone)
                            ->state(fn () => trans('pouch::strings.node.behind_proxy_warning')),
                    ]),

                Section::make()
                    ->heading(fn () => trans('pouch::strings.node.proxy_domain'))
                    ->icon(TablerIcon::World)
                    ->columns(2)
                    // Only nodes whose own FQDN cannot produce hostnames get an
                    // explicit proxy domain; for every other node the base
                    // domain stays tied to the Wings FQDN.
                    ->visible(fn (Node $node) => self::needsProxyDomain($node))
                    ->schema([
                        TextEntry::make('pouch_proxy_domain_warning')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->color(fn (Node $node) => self::proxyDomain($node) === null ? 'danger' : 'warning')
                            ->icon(TablerIcon::AlertTriangle)
                            ->state(fn (Node $node) => self::proxyDomain($node) === null
                                ? trans('pouch::strings.node.ip_fqdn_warning')
                                : trans('pouch::strings.node.ip_fqdn_override_active')),

                        TextEntry::make('pouch_proxy_domain')
                            ->label(fn () => trans('pouch::strings.node.proxy_domain'))
                            ->helperText(fn () => trans('pouch::strings.hints.proxy_domain'))
                            ->placeholder('—')
                            ->copyable()
                            ->state(fn (Node $node) => self::proxyDomain($node)),

                        Actions::make([
                            Action::make('exclude_pouch_set_proxy_domain')
                                ->label(fn (Node $node) => self::proxyDomain($node) === null
                                    ? trans('pouch::strings.actions.set_proxy_domain')
                                    : trans('pouch::strings.actions.change_proxy_domain'))
                                ->icon(TablerIcon::World)
                                ->color('primary')
                                ->authorize(fn (Node $node) => (bool) user()?->can('update', $node))
                                ->modalHeading(fn () => trans('pouch::strings.actions.set_proxy_domain'))
                                ->modalDescription(fn (Node $node) => self::routeCount($node) > 0
                                    ? trans('pouch::strings.node.proxy_domain_change_warning', ['count' => self::routeCount($node)])
                                    : trans('pouch::strings.hints.proxy_domain'))
                                ->schema(fn (Node $node) => [
                                    TextInput::make('proxy_domain')
                                        ->label(fn () => trans('pouch::strings.node.proxy_domain'))
                                        ->helperText(fn () => trans('pouch::strings.hints.proxy_domain_input'))
                                        ->required()
                                        ->maxLength(HostnameService::DOMAIN_MAX_LENGTH)
                                        ->placeholder('proxy.example.com')
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('proxy_domain', Str::lower(trim((string) $state))))
                                        // Normalise before validating so `regex` and the
                                        // uniqueness checks never trip over casing or
                                        // stray whitespace. Only the validated copy is
                                        // mutated; dehydration still lowercases below.
                                        ->mutateStateForValidationUsing(fn (?string $state) => Str::lower(trim((string) $state)))
                                        // Must stay wrapped in a closure: the rule set
                                        // contains Laravel closure rules, and Filament
                                        // would try to evaluate those as its own closures.
                                        ->rules(fn () => HostnameService::proxyDomainRules($node->id))
                                        ->validationMessages([
                                            'regex' => trans('pouch::strings.errors.proxy_domain_invalid'),
                                            'unique' => trans('pouch::strings.errors.proxy_domain_taken'),
                                        ])
                                        ->dehydrateStateUsing(fn (?string $state) => Str::lower(trim((string) $state))),
                                ])
                                ->fillForm(fn (Node $node) => ['proxy_domain' => self::proxyDomain($node)])
                                ->action(function (array $data, Node $node) {
                                    PouchNodeSetting::updateOrCreate(
                                        ['node_id' => $node->id],
                                        ['proxy_domain' => $data['proxy_domain']],
                                    );

                                    Notification::make()
                                        ->success()
                                        ->title(trans('pouch::strings.node.proxy_domain_saved'))
                                        ->send();
                                }),

                            Action::make('exclude_pouch_clear_proxy_domain')
                                ->label(fn () => trans('pouch::strings.actions.clear_proxy_domain'))
                                ->icon(TablerIcon::Trash)
                                ->color('danger')
                                ->visible(fn (Node $node) => self::proxyDomain($node) !== null)
                                ->authorize(fn (Node $node) => (bool) user()?->can('update', $node))
                                // Removing the domain would orphan every route on
                                // this node, so it stays blocked while any exist.
                                ->disabled(fn (Node $node) => self::routeCount($node) > 0)
                                ->tooltip(fn (Node $node) => self::routeCount($node) > 0
                                    ? trans('pouch::strings.node.proxy_domain_in_use', ['count' => self::routeCount($node)])
                                    : null)
                                ->requiresConfirmation()
                                ->modalHeading(fn () => trans('pouch::strings.actions.clear_proxy_domain'))
                                ->modalDescription(fn () => trans('pouch::strings.node.proxy_domain_clear_hint'))
                                ->action(function (Node $node) {
                                    if (self::routeCount($node) > 0) {
                                        Notification::make()
                                            ->danger()
                                            ->title(trans('pouch::strings.node.proxy_domain_in_use', ['count' => self::routeCount($node)]))
                                            ->send();

                                        return;
                                    }

                                    PouchNodeSetting::query()->where('node_id', $node->id)->delete();
                                    // The delete above bypasses model events.
                                    PouchNodeSetting::flushCache($node->id);

                                    Notification::make()
                                        ->success()
                                        ->title(trans('pouch::strings.node.proxy_domain_cleared'))
                                        ->send();
                                }),
                        ])->columnSpanFull(),
                    ]),

                Section::make()
                    ->heading(fn () => trans('pouch::strings.node.install'))
                    ->icon(TablerIcon::BrandDocker)
                    ->description(fn () => self::installDescription())
                    ->collapsible()
                    ->collapsed(fn (Node $node) => (bool) self::state($node)?->is_online)
                    ->visible(fn (Node $node) => self::supported($node))
                    ->schema([
                        CodeEntry::make('pouch_compose')
                            ->label('compose.yml')
                            ->grammar(Grammar::Yaml)
                            ->copyable()
                            ->columnSpanFull()
                            ->state(fn (Node $node) => app(AgentSnippetService::class)->compose($node, self::state($node))),
                    ]),

                Section::make()
                    ->heading(fn () => trans('pouch::strings.node.frontend_snippet'))
                    ->icon(TablerIcon::Route)
                    ->description(fn () => trans('pouch::strings.node.frontend_snippet_hint'))
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Node $node) => self::state($node)?->mode === ProxyMode::Behind)
                    ->schema([
                        CodeEntry::make('pouch_frontend')
                            ->hiddenLabel()
                            ->copyable()
                            ->columnSpanFull()
                            ->state(fn (Node $node) => app(AgentSnippetService::class)->frontendSnippet($node, self::state($node))),
                    ]),

                Section::make()
                    ->heading('Caddy JSON')
                    ->icon(TablerIcon::Code)
                    ->description(fn () => trans('pouch::strings.hints.websockets'))
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Node $node) => self::supported($node))
                    ->schema([
                        CodeEntry::make('pouch_caddy_config')
                            ->hiddenLabel()
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->columnSpanFull()
                            ->state(function (Node $node) {
                                $caddy = app(CaddyConfigService::class);

                                return $caddy->encode($caddy->build($node, self::stateOrNew($node)), pretty: true);
                            }),
                    ]),
            ]);
    }

    private static function supported(Node $node): bool
    {
        return app(HostnameService::class)->supportsNode($node);
    }

    /** Whether this node's FQDN cannot produce hostnames on its own. */
    private static function needsProxyDomain(Node $node): bool
    {
        return !HostnameService::fqdnIsUsable($node);
    }

    private static function proxyDomain(Node $node): ?string
    {
        return self::needsProxyDomain($node)
            ? PouchNodeSetting::domainFor($node->id)
            : null;
    }

    private static function routeCount(Node $node): int
    {
        return PouchRoute::query()->where('node_id', $node->id)->count();
    }

    private static function state(Node $node): ?PouchNodeState
    {
        static $cache = [];

        if (!array_key_exists($node->id, $cache)) {
            $cache[$node->id] = PouchNodeState::query()->where('node_id', $node->id)->first();
        }

        return $cache[$node->id];
    }

    private static function stateOrNew(Node $node): PouchNodeState
    {
        return self::state($node) ?? new PouchNodeState(['node_id' => $node->id]);
    }

    /**
     * The address the agent binds. Null while the agent terminates TLS itself,
     * where it always has to own every address of the node.
     */
    private static function listenAddress(Node $node): ?string
    {
        $state = self::state($node);

        if ($state === null || $state->mode->terminatesTls()) {
            return null;
        }

        return CaddyConfigService::listenAddress($state);
    }

    /**
     * A bind address outside loopback means the front-end proxy dials the agent
     * over a network, so its source address has to be trusted explicitly -
     * otherwise every backend sees the proxy instead of the real client.
     */
    private static function bindUntrusted(Node $node): bool
    {
        $state = self::state($node);

        if ($state === null || $state->mode->terminatesTls() || blank($state->bind_address)) {
            return false;
        }

        if (str_starts_with($state->bind_address, '127.') || $state->bind_address === '::1') {
            return false;
        }

        return CaddyConfigService::trustedRanges($state) === CaddyConfigService::DEFAULT_TRUSTED_PROXIES;
    }

    private static function inSync(Node $node): bool
    {
        $state = self::state($node);

        if (!$state?->applied_hash) {
            return false;
        }

        $caddy = app(CaddyConfigService::class);

        return $state->applied_hash === $caddy->hash($caddy->build($node, $state));
    }

    /**
     * The install hint plus a link to the agent documentation. The `agent/`
     * directory is not shipped inside the plugin zip, so the docs only exist
     * on the repository.
     */
    private static function installDescription(): HtmlString
    {
        return new HtmlString(sprintf(
            '%s <a href="%s" target="_blank" rel="noopener noreferrer" class="underline">%s</a>',
            e(trans('pouch::strings.node.install_hint')),
            e(PouchPlugin::AGENT_DOCS_URL),
            e(trans('pouch::strings.node.install_docs')),
        ));
    }

    private static function dnsMessage(Node $node): string
    {
        $resolved = self::resolved($node);

        return $resolved === null
            ? trans('pouch::strings.node.dns_missing')
            : trans('pouch::strings.node.dns_ok', ['ip' => $resolved]);
    }

    private static function resolved(Node $node): ?string
    {
        static $cache = [];

        if (!array_key_exists($node->id, $cache)) {
            $cache[$node->id] = cache()->remember(
                "pouch.dns.{$node->id}",
                now()->addMinutes(5),
                fn () => app(HostnameService::class)->resolveWildcard($node),
            );
        }

        return $cache[$node->id];
    }
}
