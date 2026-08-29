<?php

namespace Wan0v\Pouch\Filament\Admin\RelationManagers;

use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Wan0v\Pouch\Enums\BackendScheme;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Models\PouchRoute;
use Wan0v\Pouch\Services\HostnameService;

/**
 * @method Server getOwnerRecord()
 */
class PouchRoutesRelationManager extends RelationManager
{
    protected static string $relationship = 'pouchRoutes';

    protected static string|BackedEnum|null $icon = TablerIcon::World;

    public function setTitle(): string
    {
        return trans('pouch::strings.routes');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Server $ownerRecord */
        return app(HostnameService::class)->supportsNode($ownerRecord->node)
            && (bool) user()?->can('viewAny', PouchRoute::class);
    }

    public function table(Table $table): Table
    {
        $server = $this->getOwnerRecord();

        return $table
            ->heading(null)
            ->recordTitleAttribute('label')
            ->recordTitle(fn (PouchRoute $route) => $route->hostname)
            ->emptyStateHeading(trans('pouch::strings.routes'))
            ->emptyStateDescription(trans('pouch::strings.hints.label'))
            ->emptyStateIcon(TablerIcon::World)
            ->columns([
                TextColumn::make('allocation.address')
                    ->label(trans('pouch::strings.fields.allocation'))
                    ->sortable(),
                TextColumn::make('label')
                    ->label(trans('pouch::strings.fields.url'))
                    ->state(fn (PouchRoute $route) => $route->url)
                    ->url(fn (PouchRoute $route) => $route->url, shouldOpenInNewTab: true)
                    ->icon(TablerIcon::ExternalLink)
                    ->iconPosition('after')
                    ->copyable()
                    ->copyableState(fn (PouchRoute $route) => $route->url)
                    ->searchable(),
                TextColumn::make('backend_scheme')
                    ->label(trans('pouch::strings.fields.backend'))
                    ->state(fn (PouchRoute $route) => $route->backend)
                    ->badge()
                    ->color(fn (PouchRoute $route) => $route->backend_scheme === BackendScheme::Https ? 'warning' : 'gray'),
                TextColumn::make('certificate')
                    ->label(trans('pouch::strings.fields.certificate'))
                    ->badge()
                    ->state(fn (PouchRoute $route) => $this->certificateState($route))
                    ->color(fn (string $state) => $state === trans('pouch::strings.node.in_sync') ? 'success' : 'gray'),
                ToggleColumn::make('enabled')
                    ->label(trans('pouch::strings.fields.enabled'))
                    ->disabled(fn (PouchRoute $route) => !user()?->can('update', $route)),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (Schema $schema) => $this->routeForm($schema, editing: true)),
                DeleteAction::make()
                    ->after(fn (PouchRoute $route) => $this->logActivity('delete', $route)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(trans('pouch::strings.actions.create'))
                    ->icon(TablerIcon::World)
                    ->schema(fn (Schema $schema) => $this->routeForm($schema))
                    ->mutateDataUsing(function (array $data) use ($server) {
                        $allocation = Allocation::findOrFail($data['allocation_id']);

                        $data['server_id'] = $server->id;
                        $data['node_id'] = $allocation->node_id;

                        return $data;
                    })
                    ->after(fn (PouchRoute $route) => $this->logActivity('create', $route)),
            ]);
    }

    protected function routeForm(Schema $schema, bool $editing = false): Schema
    {
        $server = $this->getOwnerRecord();
        $node = $server->node;
        $hostnames = app(HostnameService::class);

        return $schema->components([
            Select::make('allocation_id')
                ->label(trans('pouch::strings.fields.allocation'))
                ->required()
                ->disabled($editing)
                ->live()
                ->options(fn (?PouchRoute $record) => $server->allocations()
                    ->where('node_id', $node->id)
                    ->when(
                        true,
                        fn ($query) => $query->whereNotIn(
                            'id',
                            PouchRoute::query()
                                ->when($record, fn ($inner) => $inner->whereKeyNot($record->getKey()))
                                ->pluck('allocation_id'),
                        ),
                    )
                    ->get()
                    ->mapWithKeys(fn (Allocation $allocation) => [$allocation->id => $allocation->address]))
                ->afterStateUpdated(function (?string $state, Set $set) use ($server, $hostnames) {
                    if (!$state) {
                        return;
                    }

                    $allocation = Allocation::find($state);

                    if ($allocation) {
                        $set('label', $hostnames->suggestLabel($server, $allocation));
                    }
                }),

            TextInput::make('label')
                ->label(trans('pouch::strings.fields.label'))
                ->required()
                ->maxLength(63)
                // The base domain is fixed and shown as a non-editable suffix.
                ->suffix('.' . $hostnames->baseDomain($node))
                ->helperText(trans('pouch::strings.hints.label'))
                // Normalise before validating so `regex` and the uniqueness
                // check see the same value that gets persisted below.
                ->mutateStateForValidationUsing(fn (?string $state) => strtolower(trim((string) $state)))
                ->rules(fn (?PouchRoute $record) => $hostnames->labelRules($node->id, $record?->getKey()))
                ->validationMessages([
                    'regex' => trans('validation.regex', ['attribute' => trans('pouch::strings.fields.label')]),
                    'not_in' => trans('pouch::strings.errors.label_reserved'),
                    'unique' => trans('pouch::strings.errors.label_taken'),
                ])
                ->default(fn () => null)
                // Must match mutateStateForValidationUsing() above, otherwise a
                // padded label would validate as trimmed but persist untrimmed.
                ->dehydrateStateUsing(fn (?string $state) => strtolower(trim((string) $state))),

            Select::make('backend_scheme')
                ->label(trans('pouch::strings.fields.backend_scheme'))
                ->options(BackendScheme::class)
                ->default(BackendScheme::Http->value)
                ->selectablePlaceholder(false)
                ->live()
                ->helperText(trans('pouch::strings.hints.backend_scheme')),

            Toggle::make('backend_tls_insecure')
                ->label(trans('pouch::strings.fields.backend_tls_insecure'))
                ->helperText(trans('pouch::strings.hints.backend_tls_insecure'))
                ->inline(false)
                ->default(false)
                ->visible(fn (Get $get) => $get('backend_scheme') === BackendScheme::Https->value),

            Toggle::make('enabled')
                ->label(trans('pouch::strings.fields.enabled'))
                ->inline(false)
                ->default(true),
        ]);
    }

    private function certificateState(PouchRoute $route): string
    {
        $state = PouchNodeState::query()->where('node_id', $route->node_id)->first();

        $status = $state?->cert_status[$route->hostname] ?? null;

        return $status === 'ready'
            ? trans('pouch::strings.node.in_sync')
            : trans('pouch::strings.node.pending');
    }

    private function logActivity(string $event, PouchRoute $route): void
    {
        Activity::event("server:pouch.$event")
            ->subject($route->allocation)
            ->property('hostname', $route->hostname)
            ->property('allocation', $route->allocation->address)
            ->log();
    }
}
