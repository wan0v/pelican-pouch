<?php

namespace Wan0v\Pouch\Services;

use App\Models\Node;
use Symfony\Component\Yaml\Yaml;
use Wan0v\Pouch\Enums\ProxyMode;
use Wan0v\Pouch\Models\PouchNodeState;

/**
 * Renders the node specific installation snippets shown in the admin UI.
 */
class AgentSnippetService
{
    public function __construct(private HostnameService $hostnames) {}

    /**
     * The recommended mode for a node based on its panel configuration.
     */
    public function recommendedMode(Node $node): ProxyMode
    {
        return $node->behind_proxy ? ProxyMode::Frontend : ProxyMode::Standalone;
    }

    /**
     * A ready-to-use docker compose file for this node.
     */
    public function compose(Node $node, ?PouchNodeState $state = null): string
    {
        $mode = $state !== null ? $state->mode : $this->recommendedMode($node);

        $environment = [
            'POUCH_MODE' => $mode->value,
            'POUCH_HTTP_PORT' => $mode === ProxyMode::Behind ? 8080 : 80,
            'POUCH_HTTPS_PORT' => 443,
            'POUCH_INTERVAL' => (int) config('pouch.agent.interval', 15),
        ];

        if ($mode->needsWingsPassthrough()) {
            $environment['POUCH_WINGS_UPSTREAM'] = '127.0.0.1:' . $node->daemon_listen;
        }

        $compose = [
            'services' => [
                'pouch-agent' => [
                    'image' => (string) config('pouch.agent.image'),
                    'container_name' => 'pelican-pouch',
                    'restart' => 'always',
                    // The agent has to reach the allocations on their
                    // node-internal addresses, and in standalone/frontend mode
                    // it also has to own ports 80 and 443 of the node.
                    'network_mode' => 'host',
                    'environment' => $environment,
                    'volumes' => [
                        '/etc/pelican/config.yml:/etc/pelican/config.yml:ro',
                        'caddy_data:/data',
                    ],
                ],
            ],
            'volumes' => [
                'caddy_data' => ['driver' => 'local'],
            ],
        ];

        return Yaml::dump($compose, 6, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * Configuration the node's existing front-end proxy needs in `behind` mode
     * so it forwards the wildcard to the agent.
     */
    public function frontendSnippet(Node $node, ?PouchNodeState $state = null): string
    {
        $wildcard = $this->hostnames->wildcard($node);
        $port = $state !== null ? $state->http_port : 8080;

        return <<<CADDY
        # Caddyfile — requires a wildcard certificate, e.g. via a DNS challenge.
        $wildcard {
            tls {
                # dns <your-provider> <credentials>
            }
            pouch 127.0.0.1:$port
        }

        # nginx equivalent
        # server {
        #     listen 443 ssl;
        #     server_name $wildcard;
        #     ssl_certificate     /path/to/fullchain.pem;
        #     ssl_certificate_key /path/to/privkey.pem;
        #
        #     location / {
        #         proxy_pass http://127.0.0.1:$port;
        #         proxy_http_version 1.1;
        #         proxy_set_header Host              \$host;
        #         proxy_set_header X-Real-IP         \$remote_addr;
        #         proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        #         proxy_set_header X-Forwarded-Proto \$scheme;
        #         # WebSocket support
        #         proxy_set_header Upgrade    \$http_upgrade;
        #         proxy_set_header Connection "upgrade";
        #     }
        # }
        CADDY;
    }
}
