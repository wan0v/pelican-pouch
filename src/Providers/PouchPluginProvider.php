<?php

namespace Wan0v\Pouch\Providers;

use App\Enums\TablerIcon;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Filament\Server\Resources\Allocations\AllocationResource;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Wan0v\Pouch\Filament\Admin\RelationManagers\PouchRoutesRelationManager;
use Wan0v\Pouch\Filament\Admin\Schemas\PouchNodeTab;
use Wan0v\Pouch\Models\PouchRoute;
use Wan0v\Pouch\Observers\AllocationObserver;
use Wan0v\Pouch\Policies\PouchRoutePolicy;

class PouchPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRelations();
        $this->registerPermissions();
        $this->registerAdminUi();
        $this->registerClientUi();
    }

    public function boot(): void
    {
        Gate::policy(PouchRoute::class, PouchRoutePolicy::class);

        Allocation::observe(AllocationObserver::class);
    }

    /**
     * Relations are attached dynamically so no core model has to be touched.
     */
    private function registerRelations(): void
    {
        Server::resolveRelationUsing(
            'pouchRoutes',
            fn (Server $server) => $server->hasMany(PouchRoute::class),
        );

        Node::resolveRelationUsing(
            'pouchRoutes',
            fn (Node $node) => $node->hasMany(PouchRoute::class),
        );

        Allocation::resolveRelationUsing(
            'pouchRoute',
            fn (Allocation $allocation) => $allocation->hasOne(PouchRoute::class),
        );
    }

    private function registerPermissions(): void
    {
        Role::registerCustomDefaultPermissions(PouchRoute::RESOURCE_NAME);
        Role::registerCustomModelIcon(PouchRoute::RESOURCE_NAME, TablerIcon::World);
    }

    private function registerAdminUi(): void
    {
        // Routes are managed right below the allocations of a server.
        ServerResource::registerCustomRelations(PouchRoutesRelationManager::class);

        // Agent status, base domain and installation snippet per node.
        EditNode::registerCustomTabs(TabPosition::After, PouchNodeTab::make());
    }

    /**
     * The client area only ever displays the published URL. Enabling a route
     * stays an administrator action.
     */
    private function registerClientUi(): void
    {
        AllocationResource::modifyTable(fn (Table $table) => $table->pushColumns([
            TextColumn::make('pouch_url')
                ->label(trans('pouch::strings.fields.web'))
                ->visibleFrom('md')
                ->placeholder('—')
                ->icon(TablerIcon::ExternalLink)
                ->iconPosition('after')
                ->copyable()
                ->state(fn (Allocation $allocation) => self::publicUrl($allocation))
                ->url(fn (Allocation $allocation) => self::publicUrl($allocation), shouldOpenInNewTab: true),
        ]));
    }

    private static function publicUrl(Allocation $allocation): ?string
    {
        // Resolved through a dynamic relation, so go through the relation API
        // instead of a magic property.
        $route = $allocation->getRelationValue('pouchRoute');

        return $route instanceof PouchRoute && $route->enabled ? $route->url : null;
    }
}
