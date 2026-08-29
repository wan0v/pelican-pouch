<?php

namespace Wan0v\Pouch\Models;

use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Administrator-managed Pouch settings of a node.
 *
 * The only setting is the explicit proxy domain used when the node's Wings
 * FQDN is a bare IP address and therefore cannot produce hostnames. For nodes
 * with a usable domain FQDN the base domain remains immutable and this record
 * is never consulted (see HostnameService::proxyDomain()).
 *
 * @property int $id
 * @property int $node_id
 * @property ?string $proxy_domain
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node $node
 */
class PouchNodeSetting extends Model
{
    protected $table = 'pouch_node_settings';

    protected $fillable = [
        'node_id',
        'proxy_domain',
    ];

    /**
     * Per-request cache. The proxy domain is resolved once per route while the
     * Caddy configuration is generated and once per row in the admin tables,
     * so this would otherwise be a guaranteed N+1.
     *
     * @var array<int, ?string>
     */
    private static array $domainCache = [];

    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting) {
            $setting->proxy_domain = filled($setting->proxy_domain)
                ? Str::lower(trim($setting->proxy_domain))
                : null;
        });

        static::saved(fn (self $setting) => self::flushCache($setting->node_id));
        static::deleted(fn (self $setting) => self::flushCache($setting->node_id));
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * The configured proxy domain of a node, or null when none is set.
     */
    public static function domainFor(int $nodeId): ?string
    {
        if (!array_key_exists($nodeId, self::$domainCache)) {
            $domain = static::query()->where('node_id', $nodeId)->value('proxy_domain');

            self::$domainCache[$nodeId] = filled($domain) ? Str::lower((string) $domain) : null;
        }

        return self::$domainCache[$nodeId];
    }

    public static function flushCache(?int $nodeId = null): void
    {
        if ($nodeId === null) {
            self::$domainCache = [];

            return;
        }

        unset(self::$domainCache[$nodeId]);
    }
}
