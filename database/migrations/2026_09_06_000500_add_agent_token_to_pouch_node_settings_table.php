<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pouch_node_settings', function (Blueprint $table) {
            // Credential of the agent container. Deliberately separate from the
            // Wings node token: the agent only ever needs `/api/remote/pouch/*`,
            // while the Wings token unlocks the whole remote API of the node and
            // signs every node JWT.
            $table->string('agent_token_id', 16)->nullable()->unique()->after('proxy_domain');
            $table->text('agent_token')->nullable()->after('agent_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('pouch_node_settings', function (Blueprint $table) {
            $table->dropColumn(['agent_token_id', 'agent_token']);
        });
    }
};
