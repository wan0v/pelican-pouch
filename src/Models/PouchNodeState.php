<?php

namespace Wan0v\Pouch\Models;

use App\Models\Node;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wan0v\Pouch\Enums\ProxyMode;

/**
 * Last known state of the Pouch agent running on a node.
 *
 * @property int $id
 * @property int $node_id
 * @property ProxyMode $mode
 * @property int $http_port
 * @property int $https_port
 * @property ?string $bind_address
 * @property ?list<string> $trusted_proxies
 * @property ?string $wings_upstream
 * @property ?string $agent_version
 * @property ?string $caddy_version
 * @property ?string $applied_hash
 * @property ?Carbon $last_seen_at
 * @property ?string $last_error
 * @property ?array<string, string> $cert_status
 * @property-read bool $is_online
 * @property-read Node $node
 */
class PouchNodeState extends Model
{
    protected $table = 'pouch_node_states';

    protected $fillable = [
        'node_id',
        'mode',
        'http_port',
        'https_port',
        'bind_address',
        'trusted_proxies',
        'wings_upstream',
        'agent_version',
        'caddy_version',
        'applied_hash',
        'last_seen_at',
        'last_error',
        'cert_status',
    ];

    protected $attributes = [
        'mode' => 'standalone',
        'http_port' => 80,
        'https_port' => 443,
    ];

    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'mode' => ProxyMode::class,
            'http_port' => 'integer',
            'https_port' => 'integer',
            'trusted_proxies' => 'array',
            'last_seen_at' => 'datetime',
            'cert_status' => 'array',
        ];
    }

    /**
     * The agent is considered online when it synced recently. The agent polls
     * every `pouch.agent.interval` seconds, so a multiple of that is
     * used as the grace period.
     */
    protected function isOnline(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->last_seen_at !== null
                && $this->last_seen_at->gt(now()->subSeconds((int) config('pouch.agent.offline_after', 60))),
        );
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
