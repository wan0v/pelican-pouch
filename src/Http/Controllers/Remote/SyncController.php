<?php

namespace Wan0v\Pouch\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Wan0v\Pouch\Http\Requests\SyncRequest;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Models\PouchRoute;
use Wan0v\Pouch\Services\CaddyConfigService;
use Wan0v\Pouch\Services\HostnameService;

/**
 * Single endpoint the agent talks to.
 *
 * The request doubles as a heartbeat (the agent reports what it currently runs)
 * and as a config poll (the response contains the desired Caddy config plus its
 * hash, so the agent only reloads when something actually changed).
 */
class SyncController extends Controller
{
    public function __construct(
        private CaddyConfigService $caddy,
        private HostnameService $hostnames,
    ) {}

    public function __invoke(SyncRequest $request): JsonResponse
    {
        $node = $request->node();

        $state = PouchNodeState::updateOrCreate(
            ['node_id' => $node->id],
            [
                'mode' => $request->mode(),
                'http_port' => $request->integer('http_port'),
                'https_port' => $request->integer('https_port'),
                'bind_address' => $request->input('bind_address'),
                'trusted_proxies' => $request->input('trusted_proxies'),
                'wings_upstream' => $request->input('wings_upstream'),
                'agent_version' => $request->input('agent_version'),
                'caddy_version' => $request->input('caddy_version'),
                'applied_hash' => $request->input('applied_hash'),
                'last_seen_at' => now(),
                'last_error' => $request->input('last_error'),
                'cert_status' => $request->input('cert_status'),
            ],
        );

        if (!$this->hostnames->supportsNode($node)) {
            return new JsonResponse([
                'error' => trans('pouch::strings.errors.node_needs_proxy_domain', ['node' => $node->name]),
            ], JsonResponse::HTTP_CONFLICT);
        }

        // Core releases allocations with query builder updates, which bypass
        // model events. Clean up before generating so a released allocation is
        // never published.
        PouchRoute::pruneStaleForNode($node->id);

        $result = $this->caddy->generate($node, $state);

        return new JsonResponse([
            'hash' => $result['hash'],
            'generated_at' => now()->toIso8601String(),
            'base_domain' => $this->hostnames->baseDomain($node),
            'poll_interval' => (int) config('pouch.agent.interval', 15),
            'config' => $result['config'],
        ]);
    }
}
