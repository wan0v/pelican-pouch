<?php

namespace Wan0v\Pouch\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use Wan0v\Pouch\Enums\ProxyMode;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Services\CaddyConfigService;
use Wan0v\Pouch\Services\HostnameService;

use function Laravel\Prompts\select;

class PreviewCaddyConfigCommand extends Command
{
    protected $signature = 'p:pouch:preview
                            {node? : ID or name of the node}
                            {--mode= : Override the reported agent mode (standalone, frontend, behind)}
                            {--http-port= : Override the agent HTTP port}
                            {--https-port= : Override the agent HTTPS port}';

    protected $description = 'Print the Caddy configuration the Pouch agent would receive for a node';

    public function handle(CaddyConfigService $caddy, HostnameService $hostnames): int
    {
        $node = $this->resolveNode();

        if (!$node) {
            $this->error('No node found.');

            return self::FAILURE;
        }

        if (!$hostnames->supportsNode($node)) {
            $this->error(trans('pouch::strings.errors.node_needs_proxy_domain', ['node' => $node->name]));

            return self::FAILURE;
        }

        $state = PouchNodeState::firstOrNew(['node_id' => $node->id]);

        if ($mode = $this->option('mode')) {
            $parsed = ProxyMode::tryFrom($mode);

            if (!$parsed) {
                $this->error("Unknown mode [$mode]. Valid modes: standalone, frontend, behind.");

                return self::FAILURE;
            }

            $state->mode = $parsed;
        }

        if ($port = $this->option('http-port')) {
            $state->http_port = (int) $port;
        }

        if ($port = $this->option('https-port')) {
            $state->https_port = (int) $port;
        }

        $result = $caddy->generate($node, $state);

        $this->line('# node:  ' . $node->name . ' (' . $node->fqdn . ')');
        $this->line('# base:  ' . $hostnames->wildcard($node)
            . ($hostnames->proxyDomain($node) !== null ? '  (configured proxy domain)' : ''));
        $this->line('# mode:  ' . $state->mode->value);
        $this->line('# hash:  ' . $result['hash']);
        $this->newLine();
        $this->line($caddy->encode($result['config'], pretty: true));

        return self::SUCCESS;
    }

    private function resolveNode(): ?Node
    {
        $argument = $this->argument('node');

        if ($argument) {
            return Node::query()
                ->where('id', $argument)
                ->orWhere('name', $argument)
                ->orWhere('fqdn', $argument)
                ->first();
        }

        $nodes = Node::query()->orderBy('name')->pluck('name', 'id')->all();

        if ($nodes === []) {
            return null;
        }

        return Node::find(select('Which node?', $nodes));
    }
}
