<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pouch_node_states', function (Blueprint $table) {
            // Local address the agent binds in `behind` mode. Null keeps the
            // historical loopback default, so agents that do not report it
            // receive exactly the configuration they received before.
            $table->string('bind_address', 45)->nullable()->after('https_port');

            // CIDR ranges whose X-Forwarded-* headers Caddy may trust. Only
            // relevant while TLS is terminated upstream.
            $table->json('trusted_proxies')->nullable()->after('bind_address');
        });
    }

    public function down(): void
    {
        Schema::table('pouch_node_states', function (Blueprint $table) {
            $table->dropColumn(['bind_address', 'trusted_proxies']);
        });
    }
};
