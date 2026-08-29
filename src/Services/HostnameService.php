<?php

namespace Wan0v\Pouch\Services;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Wan0v\Pouch\Models\PouchNodeSetting;
use Wan0v\Pouch\Models\PouchRoute;

class HostnameService
{
    /** RFC 1123 hostname label. */
    public const LABEL_REGEX = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    private const SUFFIX_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** The longest a full hostname may be. */
    public const DOMAIN_MAX_LENGTH = 253;

    /** A domain name of at least two labels, e.g. `proxy.example.com`. */
    public const DOMAIN_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    /**
     * The base domain of a node.
     *
     * It is the node's Wings FQDN and cannot be configured. The single
     * exception is a node whose FQDN is a bare IP address: hostnames cannot be
     * derived from an IP, so an explicit proxy domain may be stored for it.
     */
    public function baseDomain(Node $node): string
    {
        return self::resolveBaseDomain($node);
    }

    /**
     * Static counterpart of baseDomain() for callers without a container, such
     * as the hostname accessor on PouchRoute. This is the single source
     * of truth for how a base domain is derived.
     */
    public static function resolveBaseDomain(Node $node): string
    {
        if (!self::fqdnIsUsable($node)) {
            $override = PouchNodeSetting::domainFor($node->id);

            if ($override !== null) {
                return $override;
            }
        }

        return Str::lower($node->fqdn);
    }

    public function wildcard(Node $node): string
    {
        return '*.' . $this->baseDomain($node);
    }

    public function hostname(Node $node, string $label): string
    {
        return Str::lower($label) . '.' . $this->baseDomain($node);
    }

    /**
     * Whether the node's own FQDN can be used as a base domain. A node that
     * uses a bare IP address has no domain to create subdomains under.
     */
    public static function fqdnIsUsable(Node $node): bool
    {
        return !is_ip($node->fqdn) && filled($node->fqdn) && str_contains($node->fqdn, '.');
    }

    /**
     * The explicitly configured proxy domain of a node, or null.
     *
     * Deliberately only honoured when the FQDN itself is unusable — for every
     * other node the base domain stays tied to the Wings FQDN.
     */
    public function proxyDomain(Node $node): ?string
    {
        if (self::fqdnIsUsable($node)) {
            return null;
        }

        return PouchNodeSetting::domainFor($node->id);
    }

    /**
     * Whether this node requires an explicit proxy domain because its FQDN
     * cannot produce hostnames. Drives the node's Pouch tab.
     */
    public function needsProxyDomain(Node $node): bool
    {
        return !self::fqdnIsUsable($node);
    }

    /**
     * Whether hostnames can be derived for this node at all.
     */
    public function supportsNode(Node $node): bool
    {
        return self::fqdnIsUsable($node) || $this->proxyDomain($node) !== null;
    }

    /**
     * The vhost Wings itself is reached under in `frontend` mode.
     *
     * This is always the real FQDN and never the proxy domain. A node with an
     * IP FQDN has no Wings vhost at all, hence the null.
     */
    public function wingsHost(Node $node): ?string
    {
        return self::fqdnIsUsable($node) ? Str::lower($node->fqdn) : null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function guardNode(Node $node): void
    {
        throw_unless(
            $this->supportsNode($node),
            new InvalidArgumentException(trans('pouch::strings.errors.node_needs_proxy_domain', ['node' => $node->name])),
        );
    }

    /**
     * Validation rules for the proxy domain of a node.
     *
     * The domain has to be unique across nodes: label uniqueness is scoped by
     * node, so two nodes sharing a base domain could mint the same hostname
     * twice and fight over the same certificate.
     *
     * Static so the node's Pouch tab — a schema built during service
     * provider registration, without a container entry point — can use it.
     *
     * Contains Laravel closure rules, so it must never be handed to Filament's
     * `->rules()` as a bare array: Filament evaluates every array element as
     * one of its own closures and blows up on the `$attribute` parameter. Pass
     * it as `->rules(fn () => ...)`, whose return value is merged unevaluated.
     *
     * @return array<int, mixed>
     */
    public static function proxyDomainRules(int $nodeId): array
    {
        return [
            'required',
            'string',
            'max:' . self::DOMAIN_MAX_LENGTH,
            'lowercase',
            'regex:' . self::DOMAIN_REGEX,
            function (string $attribute, mixed $value, Closure $fail) {
                if (is_ip((string) $value)) {
                    $fail(trans('pouch::strings.errors.proxy_domain_invalid'));
                }
            },
            function (string $attribute, mixed $value, Closure $fail) use ($nodeId) {
                $domain = Str::lower(trim((string) $value));

                $usedByNode = Node::query()
                    ->whereKeyNot($nodeId)
                    ->whereRaw('LOWER(fqdn) = ?', [$domain])
                    ->exists();

                if ($usedByNode) {
                    $fail(trans('pouch::strings.errors.proxy_domain_taken'));
                }
            },
            Rule::unique('pouch_node_settings', 'proxy_domain')->ignore($nodeId, 'node_id'),
        ];
    }

    /**
     * Suggest a free label for an allocation, e.g. `chat-a1b2c3`.
     */
    public function suggestLabel(Server $server, Allocation $allocation): string
    {
        $slugSource = filled($allocation->notes) ? $allocation->notes : $server->name;

        $slug = Str::of($slugSource)
            ->ascii()
            ->lower()
            ->slug()
            ->limit((int) config('pouch.hostname.label_slug_length', 24), '')
            ->trim('-')
            ->value();

        // A label must start with an alphanumeric character; fall back to the
        // short uuid when the name slugs down to nothing usable.
        if ($slug === '' || !ctype_alnum($slug[0])) {
            $slug = Str::lower($server->uuid_short);
        }

        $length = (int) config('pouch.hostname.suffix_length', 6);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $label = $slug . '-' . $this->randomSuffix($length);

            if ($this->isAvailable($allocation->node_id, $label)) {
                return $label;
            }
        }

        // Extremely unlikely; widen the suffix rather than fail.
        return $slug . '-' . $this->randomSuffix($length + 4);
    }

    public function randomSuffix(int $length): string
    {
        $alphabet = self::SUFFIX_ALPHABET;
        $suffix = '';

        for ($i = 0; $i < $length; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }

    public function isReserved(string $label): bool
    {
        $reserved = array_map('strtolower', (array) config('pouch.reserved_labels', []));

        return in_array(Str::lower($label), $reserved, true);
    }

    public function isAvailable(int $nodeId, string $label, ?int $exceptRouteId = null): bool
    {
        if ($this->isReserved($label)) {
            return false;
        }

        return !PouchRoute::query()
            ->where('node_id', $nodeId)
            ->where('label', Str::lower($label))
            ->when($exceptRouteId, fn ($query) => $query->whereKeyNot($exceptRouteId))
            ->exists();
    }

    /**
     * Validation rules for the label form field.
     *
     * Always pass this to Filament as `->rules(fn () => ...)`; see
     * proxyDomainRules() for why a bare array is a trap once a closure rule is
     * added here.
     *
     * @return array<int, mixed>
     */
    public function labelRules(int $nodeId, ?int $exceptRouteId = null): array
    {
        return [
            'required',
            'string',
            'min:1',
            'max:63',
            'regex:' . self::LABEL_REGEX,
            'not_in:' . implode(',', (array) config('pouch.reserved_labels', [])),
            Rule::unique('pouch_routes', 'label')
                ->where('node_id', $nodeId)
                ->when($exceptRouteId !== null, fn ($rule) => $rule->ignore($exceptRouteId)),
        ];
    }

    /**
     * Resolve the wildcard DNS record for a node so the UI can tell the admin
     * whether DNS is set up. Returns null when nothing resolves.
     */
    public function resolveWildcard(Node $node): ?string
    {
        if (!$this->supportsNode($node)) {
            return null;
        }

        // Wildcards cannot be resolved directly, so probe a random label.
        $probe = 'pouch-probe-' . $this->randomSuffix(8) . '.' . $this->baseDomain($node);

        $ip = @gethostbyname($probe);

        return is_ip($ip) ? $ip : null;
    }
}
