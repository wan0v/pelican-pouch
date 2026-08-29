<?php

namespace Wan0v\Pouch\Services;

use App\Models\Node;
use Illuminate\Support\Collection;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Models\PouchRoute;

/**
 * Builds the complete Caddy JSON configuration for a node.
 *
 * This is the only place in the plugin that knows about Caddy's config schema.
 * The agent is intentionally dumb: it posts its own state and applies whatever
 * comes back, which keeps all logic here where it can be inspected and tested.
 */
class CaddyConfigService
{
    /** Name of the HTTP server inside the generated config. */
    public const SERVER_NAME = 'pelican';

    public function __construct(private HostnameService $hostnames) {}

    /**
     * @return array{hash: string, config: array<string, mixed>}
     */
    public function generate(Node $node, PouchNodeState $state): array
    {
        $config = $this->build($node, $state);

        return [
            'hash' => $this->hash($config),
            'config' => $config,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Node $node, PouchNodeState $state): array
    {
        $mode = $state->mode;
        $routes = $this->routesFor($node);

        $httpRoutes = [];

        // A node with an IP FQDN has no Wings vhost to keep serving, so there is
        // nothing to pass through even in `frontend` mode.
        if ($mode->needsWingsPassthrough() && ($wingsHost = $this->hostnames->wingsHost($node)) !== null) {
            $httpRoutes[] = $this->wingsRoute($node, $state, $wingsHost);
        }

        foreach ($routes as $route) {
            $httpRoutes[] = $this->proxyRoute($route);
        }

        // Anything that is not explicitly published must not fall through to a
        // random backend, so terminate with an explicit 404.
        $httpRoutes[] = [
            'handle' => [[
                'handler' => 'static_response',
                'status_code' => 404,
            ]],
            'terminal' => true,
        ];

        $server = [
            'listen' => $mode->terminatesTls()
                ? [':' . $state->https_port]
                : ['127.0.0.1:' . $state->http_port],
            'routes' => $httpRoutes,
        ];

        if (!$mode->terminatesTls()) {
            // TLS is terminated by the front-end proxy; never try to issue certs.
            $server['automatic_https'] = ['disable' => true];
            // Trust the X-Forwarded-* headers the front-end proxy sets.
            $server['trusted_proxies'] = [
                'source' => 'static',
                'ranges' => ['127.0.0.1/32', '::1/128'],
            ];
        }

        $config = [
            'apps' => [
                'http' => [
                    'http_port' => $state->http_port,
                    'https_port' => $state->https_port,
                    'servers' => [
                        self::SERVER_NAME => $server,
                    ],
                ],
            ],
        ];

        if ($mode->terminatesTls() && ($tls = $this->tlsApp()) !== null) {
            $config['apps']['tls'] = $tls;
        }

        return $config;
    }

    /**
     * All enabled routes of a node, ordered deterministically so the resulting
     * hash is stable across requests.
     *
     * @return Collection<int, PouchRoute>
     */
    public function routesFor(Node $node): Collection
    {
        return PouchRoute::query()
            ->with(['allocation', 'node'])
            ->where('node_id', $node->id)
            ->enabled()
            // Ignore routes whose allocation got released in the meantime.
            ->whereHas('allocation', fn ($query) => $query->whereNotNull('server_id'))
            ->orderBy('label')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function proxyRoute(PouchRoute $route): array
    {
        return [
            '@id' => 'route-' . $route->uuid,
            'match' => [[
                'host' => [$route->hostname],
            ]],
            'handle' => [[
                'handler' => 'reverse_proxy',
                // Always the raw allocation ip/port, never the alias.
                'upstreams' => [[
                    'dial' => $this->dial($route->allocation->ip, $route->allocation->port),
                ]],
                'transport' => $route->backend_scheme->transport($route->backend_tls_insecure),
            ]],
            'terminal' => true,
        ];
    }

    /**
     * In `frontend` mode the agent replaces the node's previous front-end proxy,
     * so it has to keep serving the Wings vhost itself.
     *
     * The host matcher is always the node's real FQDN, never a configured proxy
     * domain — Wings is reached under its own hostname.
     *
     * @return array<string, mixed>
     */
    private function wingsRoute(Node $node, PouchNodeState $state, string $wingsHost): array
    {
        $upstream = $state->wings_upstream ?: '127.0.0.1:' . $node->daemon_listen;

        return [
            '@id' => 'wings',
            'match' => [[
                'host' => [$wingsHost],
            ]],
            'handle' => [[
                'handler' => 'reverse_proxy',
                'upstreams' => [['dial' => $upstream]],
                'transport' => ['protocol' => 'http'],
            ]],
            'terminal' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tlsApp(): ?array
    {
        $email = config('pouch.acme.email');
        $ca = config('pouch.acme.ca');

        if (blank($email) && blank($ca)) {
            // Let Caddy use its built-in issuer defaults.
            return null;
        }

        $issuer = ['module' => 'acme'];

        if (filled($email)) {
            $issuer['email'] = $email;
        }

        if (filled($ca)) {
            $issuer['ca'] = $ca;
        }

        return [
            'automation' => [
                'policies' => [[
                    'issuers' => [$issuer],
                ]],
            ],
        ];
    }

    private function dial(string $ip, int $port): string
    {
        return (is_ipv6($ip) ? "[$ip]" : $ip) . ':' . $port;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function hash(array $config): string
    {
        return hash('sha256', $this->encode($config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function encode(array $config, bool $pretty = false): string
    {
        return json_encode(
            $config,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0),
        );
    }
}
