<?php

namespace Wan0v\Pouch\Tests;

use App\Models\Node;
use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Testing\TestResponse;
use Wan0v\Pouch\Providers\PouchRouteProvider;

/**
 * Base class for the plugin's tests.
 *
 * PluginService returns early while tests run (`runningUnitTests()`), so nothing
 * of the plugin is loaded by default. Everything the loader would do — config,
 * translations, migrations, providers — happens here instead.
 */
abstract class PouchTestCase extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pouch', require plugin_path('pouch', 'config/pouch.php'));

        $this->app->make('translator')->addNamespace('pouch', plugin_path('pouch', 'lang'));

        $this->artisan('migrate', ['--path' => 'plugins/pouch/database/migrations'])->run();

        // A RouteServiceProvider registered after the application booted runs its
        // route callback immediately, so the sync endpoint exists from here on.
        $this->app->register(PouchRouteProvider::class);
    }

    /**
     * A node Pouch can actually serve — the factory hands out an IP as the FQDN,
     * which cannot produce hostnames.
     */
    protected function pouchNode(array $attributes = []): Node
    {
        return Node::factory()->create(array_merge([
            'fqdn' => 'node-' . uniqid() . '.example.com',
        ], $attributes));
    }

    /**
     * The panel wraps validation errors in its own envelope, so Laravel's
     * assertJsonValidationErrors() never matches. Core's remote API tests use
     * the same path assertion.
     */
    protected function assertRejected(TestResponse $response, string $field): void
    {
        $response
            ->assertStatus(422)
            ->assertJsonPath('errors.0.meta.source_field', $field);
    }

    /**
     * A payload shaped like the one the agent posts.
     */
    protected function syncPayload(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'standalone',
            'http_port' => 80,
            'https_port' => 443,
            'agent_version' => '1.2.0',
            'caddy_version' => 'v2.8.4',
        ], $overrides);
    }
}
