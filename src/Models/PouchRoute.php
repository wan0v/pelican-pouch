<?php

namespace Wan0v\Pouch\Models;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Traits\HasValidation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Wan0v\Pouch\Enums\BackendScheme;
use Wan0v\Pouch\Services\HostnameService;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int $node_id
 * @property int $allocation_id
 * @property string $label
 * @property bool $enabled
 * @property BackendScheme $backend_scheme
 * @property bool $backend_tls_insecure
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $hostname
 * @property-read string $url
 * @property-read string $backend
 * @property-read Server $server
 * @property-read Node $node
 * @property-read Allocation $allocation
 */
class PouchRoute extends Model
{
    use HasValidation;

    public const RESOURCE_NAME = 'pouchRoute';

    protected $table = 'pouch_routes';

    protected $fillable = [
        'server_id',
        'node_id',
        'allocation_id',
        'label',
        'enabled',
        'backend_scheme',
        'backend_tls_insecure',
    ];

    protected $attributes = [
        'enabled' => true,
        'backend_scheme' => 'http',
        'backend_tls_insecure' => false,
    ];

    /** @var array<array-key, string[]> */
    public static array $validationRules = [
        'server_id' => ['required', 'exists:servers,id'],
        'node_id' => ['required', 'exists:nodes,id'],
        'allocation_id' => ['required', 'exists:allocations,id', 'unique:pouch_routes,allocation_id'],
        'label' => ['required', 'string', 'min:1', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
        'enabled' => ['boolean'],
        'backend_scheme' => ['required', 'string', 'in:http,https'],
        'backend_tls_insecure' => ['boolean'],
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'node_id' => 'integer',
            'allocation_id' => 'integer',
            'enabled' => 'boolean',
            'backend_scheme' => BackendScheme::class,
            'backend_tls_insecure' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $route) {
            $route->uuid ??= Str::uuid()->toString();
        });
    }

    /**
     * The public hostname of this route.
     *
     * Only the label is stored; the base domain is resolved at read time by
     * HostnameService so it can never drift away from the node configuration.
     */
    protected function hostname(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->label . '.' . HostnameService::resolveBaseDomain($this->node),
        );
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => 'https://' . $this->hostname,
        );
    }

    /** The internal backend address Caddy dials, e.g. `10.10.10.2:5555`. */
    protected function backend(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->backend_scheme->value . '://' . $this->allocation->ip . ':' . $this->allocation->port,
        );
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    /** @param Builder<self> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Routes whose allocation no longer matches the server/node they were
     * published for.
     *
     * The core detach actions release allocations with a query builder update
     * (see AllocationResource and AllocationsRelationManager), which bypasses
     * model events and therefore our observer. This scope is the safety net.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->whereDoesntHave('allocation', function (Builder $allocation) {
            $allocation
                ->whereColumn('allocations.server_id', 'pouch_routes.server_id')
                ->whereColumn('allocations.node_id', 'pouch_routes.node_id');
        });
    }

    /**
     * Remove stale routes of a node. Returns the number of deleted routes.
     */
    public static function pruneStaleForNode(int $nodeId): int
    {
        $deleted = 0;

        static::query()
            ->where('node_id', $nodeId)
            ->stale()
            ->get()
            ->each(function (self $route) use (&$deleted) {
                $route->delete();
                $deleted++;
            });

        return $deleted;
    }
}
