<?php

namespace Wan0v\Pouch\Tests\Integration;

use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Wan0v\Pouch\Models\PouchNodeSetting;
use Wan0v\Pouch\Models\PouchNodeState;
use Wan0v\Pouch\Tests\PouchTestCase;

class SyncAuthenticationTest extends PouchTestCase
{
    public function test_agent_token_is_accepted(): void
    {
        $node = $this->pouchNode();
        $token = PouchNodeSetting::generateAgentToken($node);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertOk()
            ->assertJsonStructure(['hash', 'generated_at', 'base_domain', 'poll_interval', 'config']);

        $this->assertDatabaseHas('pouch_node_states', ['node_id' => $node->id]);
    }

    public function test_wrong_agent_token_is_rejected(): void
    {
        $node = $this->pouchNode();
        $tokenId = explode('.', PouchNodeSetting::generateAgentToken($node))[0];

        $this->withHeader('Authorization', "Bearer $tokenId.wrong-secret")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertForbidden();
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->pouchNode();

        $this->withHeader('Authorization', 'Bearer nosuchtokenid.nosuchsecret')
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertForbidden();
    }

    public function test_a_node_cannot_use_the_agent_token_of_another_node(): void
    {
        $this->pouchNode();
        $other = $this->pouchNode();

        $token = PouchNodeSetting::generateAgentToken($other);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertOk();

        // The credential identifies the node; nothing in the body does.
        $this->assertDatabaseHas('pouch_node_states', ['node_id' => $other->id]);
        $this->assertSame(1, PouchNodeState::query()->count());
    }

    public function test_missing_authorization_header_is_unauthorized(): void
    {
        $this->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertUnauthorized();
    }

    public function test_malformed_authorization_header_is_a_bad_request(): void
    {
        $this->withHeader('Authorization', 'Bearer no-dot-in-here')
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertStatus(400);
    }

    /**
     * The old agent read `<token_id>.<token>` out of the mounted Wings config.
     * That path is closed: after the update such an agent stops syncing until
     * its own credential is deployed.
     */
    public function test_wings_token_is_rejected_without_an_agent_token(): void
    {
        $node = $this->pouchNode();

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id.$node->daemon_token")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('pouch_node_states', ['node_id' => $node->id]);
    }

    public function test_wings_token_is_rejected_with_an_agent_token(): void
    {
        $node = $this->pouchNode();
        PouchNodeSetting::generateAgentToken($node);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id.$node->daemon_token")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertForbidden();
    }

    public function test_regenerating_invalidates_the_previous_agent_token(): void
    {
        $node = $this->pouchNode();
        $first = PouchNodeSetting::generateAgentToken($node);
        $second = PouchNodeSetting::generateAgentToken($node);

        $this->assertNotSame($first, $second);

        $this->withHeader('Authorization', "Bearer $first")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertForbidden();

        $this->withHeader('Authorization', "Bearer $second")
            ->postJson('/api/remote/pouch/sync', $this->syncPayload())
            ->assertOk();
    }

    public function test_the_agent_token_is_not_stored_in_plaintext(): void
    {
        $node = $this->pouchNode();
        $secret = explode('.', PouchNodeSetting::generateAgentToken($node))[1];

        // Through the model the `encrypted` cast would hand back the plaintext,
        // so the raw column is what has to be checked.
        $stored = (string) DB::table('pouch_node_settings')->where('node_id', $node->id)->value('agent_token');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
    }

    /**
     * The Wings token unlocks the node's entire remote API, so the agent must
     * never be able to reach anything but its own endpoint with its credential.
     */
    public function test_the_agent_token_does_not_open_the_core_remote_api(): void
    {
        $node = $this->pouchNode();
        $token = PouchNodeSetting::generateAgentToken($node);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/remote/servers')
            ->assertStatus(404);
    }

    public function test_a_node_without_a_settings_row_has_no_credential(): void
    {
        $node = Node::factory()->create();

        $this->assertNull(PouchNodeSetting::query()->where('node_id', $node->id)->value('agent_token_id'));
    }
}
