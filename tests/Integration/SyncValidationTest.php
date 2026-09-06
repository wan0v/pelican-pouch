<?php

namespace Wan0v\Pouch\Tests\Integration;

use App\Models\Node;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Wan0v\Pouch\Models\PouchNodeSetting;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Services\CaddyConfigService;
use Wan0v\Pouch\Tests\PouchTestCase;

/**
 * Everything the agent reports shapes the configuration the node's public proxy
 * then runs, so it is treated as untrusted input.
 */
class SyncValidationTest extends PouchTestCase
{
    private Node $node;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = $this->pouchNode();
        $this->token = PouchNodeSetting::generateAgentToken($this->node);
    }

    private function sync(array $overrides = []): TestResponse
    {
        return $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload($overrides));
    }

    public function test_a_payload_from_an_older_agent_is_still_accepted(): void
    {
        // No bind_address, trusted_proxies, applied_hash or cert_status.
        $this->withHeader('Authorization', "Bearer $this->token")
            ->postJson('/api/remote/pouch/sync', [
                'mode' => 'standalone',
                'http_port' => 80,
                'https_port' => 443,
            ])
            ->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function invalidUpstreams(): array
    {
        return [
            'scheme' => ['http://127.0.0.1:8080'],
            'path' => ['127.0.0.1:8080/admin'],
            'space' => ['127.0.0.1:8080 evil'],
            'no port' => ['127.0.0.1'],
            'port out of range' => ['127.0.0.1:70000'],
            'empty host' => [':8080'],
        ];
    }

    #[DataProvider('invalidUpstreams')]
    public function test_invalid_wings_upstreams_are_rejected(string $upstream): void
    {
        $this->assertRejected(
            $this->sync(['mode' => 'frontend', 'wings_upstream' => $upstream]),
            'wings_upstream',
        );
    }

    /** @return array<string, array{string}> */
    public static function validUpstreams(): array
    {
        return [
            'ipv4' => ['127.0.0.1:8080'],
            'ipv6' => ['[::1]:8080'],
            'hostname' => ['wings.example.com:8080'],
        ];
    }

    #[DataProvider('validUpstreams')]
    public function test_valid_wings_upstreams_are_accepted(string $upstream): void
    {
        $this->sync(['mode' => 'frontend', 'wings_upstream' => $upstream])->assertOk();

        $this->assertSame($upstream, PouchNodeState::query()->where('node_id', $this->node->id)->value('wings_upstream'));
    }

    public function test_a_trusted_proxy_range_covering_the_internet_is_rejected(): void
    {
        $this->assertRejected(
            $this->sync(['mode' => 'behind', 'http_port' => 8080, 'trusted_proxies' => ['0.0.0.0/0']]),
            'trusted_proxies.0',
        );

        $this->assertRejected(
            $this->sync(['mode' => 'behind', 'http_port' => 8080, 'trusted_proxies' => ['::/0']]),
            'trusted_proxies.0',
        );
    }

    public function test_a_narrow_trusted_proxy_range_is_accepted(): void
    {
        $this->sync(['mode' => 'behind', 'http_port' => 8080, 'trusted_proxies' => ['10.0.0.0/24']])
            ->assertOk();
    }

    /**
     * Caddy takes bare addresses as well as CIDR in `trusted_proxies.ranges`,
     * and naming the one front-end proxy is the tightest possible setting. The
     * prefix limits must not get in the way of it.
     */
    public function test_a_single_ip_is_a_valid_trusted_proxy(): void
    {
        $response = $this->sync([
            'mode' => 'behind',
            'http_port' => 8080,
            'trusted_proxies' => ['10.0.0.2', '2001:db8::1'],
        ])->assertOk();

        $ranges = $response->json('config.apps.http.servers.' . CaddyConfigService::SERVER_NAME . '.trusted_proxies.ranges');

        $this->assertContains('10.0.0.2', $ranges);
        $this->assertContains('2001:db8::1', $ranges);
        // Loopback stays merged in regardless of what the node reports.
        $this->assertContains('127.0.0.1/32', $ranges);
    }

    /**
     * The narrowest CIDR forms of the same thing, for the same reason.
     */
    public function test_host_prefixes_are_accepted(): void
    {
        $this->sync(['mode' => 'behind', 'http_port' => 8080, 'trusted_proxies' => ['10.0.0.2/32', '2001:db8::1/128']])
            ->assertOk();
    }

    public function test_cert_status_is_bounded(): void
    {
        $certs = [];
        for ($i = 0; $i <= 200; $i++) {
            $certs["host-$i.example.com"] = 'ready';
        }

        $this->assertRejected($this->sync(['cert_status' => $certs]), 'cert_status');
    }

    public function test_cert_status_keys_have_to_look_like_hostnames(): void
    {
        $this->assertRejected(
            $this->sync(['cert_status' => ['<script>alert(1)</script>' => 'ready']]),
            'cert_status',
        );
    }

    /**
     * An agent that reports neither a bind address nor trusted proxies has to
     * receive byte-identical configuration to the one it got before those
     * fields existed, otherwise every installation reloads Caddy once for
     * nothing.
     */
    public function test_a_behind_mode_agent_without_extras_keeps_the_historical_config(): void
    {
        $response = $this->sync(['mode' => 'behind', 'http_port' => 8080])->assertOk();

        $server = $response->json('config.apps.http.servers.' . CaddyConfigService::SERVER_NAME);

        $this->assertSame(['127.0.0.1:8080'], $server['listen']);
        $this->assertSame(['127.0.0.1/32', '::1/128'], $server['trusted_proxies']['ranges']);
        $this->assertTrue($server['automatic_https']['disable']);
    }
}
