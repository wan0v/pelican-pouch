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
 * @property ?string $agent_token_id
 * @property ?string $agent_token
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
     * Never mass assignable and never serialized — the agent token is handed
     * out exactly once, when it is generated.
     */
    protected $hidden = [
        'agent_token_id',
        'agent_token',
    ];

    /** Length of the public part of the agent credential. */
    public const AGENT_TOKEN_ID_LENGTH = 16;

    /** Length of the secret part of the agent credential. */
    public const AGENT_TOKEN_LENGTH = 64;

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
            // Reversible, like the core node token (Node::casts()): the panel
            // has to compare the plaintext the agent presents.
            'agent_token' => 'encrypted',
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
     * Issue a fresh agent credential for a node and return the full
     * `<token_id>.<token>` string. This is the only moment the secret is
     * readable; afterwards only its id is shown.
     *
     * Until a node has one, its agent cannot sync at all — there is no fallback
     * to the Wings token. Regenerating locks out the agent that currently runs
     * on the node until the new credential is deployed there.
     */
    public static function generateAgentToken(Node $node): string
    {
        $setting = static::query()->firstOrNew(['node_id' => $node->id]);

        $setting->agent_token_id = Str::random(self::AGENT_TOKEN_ID_LENGTH);
        $setting->agent_token = Str::random(self::AGENT_TOKEN_LENGTH);
        $setting->save();

        return $setting->agent_token_id . '.' . $setting->agent_token;
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
