<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pouch_node_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained('nodes')->cascadeOnDelete();

            $table->string('mode', 16)->default('standalone');
            $table->unsignedSmallInteger('http_port')->default(80);
            $table->unsignedSmallInteger('https_port')->default(443);
            $table->string('wings_upstream')->nullable();

            $table->string('agent_version')->nullable();
            $table->string('caddy_version')->nullable();

            // Hash of the configuration the agent has actually applied.
            $table->string('applied_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('cert_status')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pouch_node_states');
    }
};
