<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pouch_routes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            // One route per allocation for now.
            $table->foreignId('allocation_id')->unique()->constrained('allocations')->cascadeOnDelete();

            // Only the label is stored. The base domain is derived from the
            // node's Wings FQDN (or, for nodes with an IP FQDN, from
            // pouch_node_settings) and therefore has no column here.
            $table->string('label', 63);

            $table->boolean('enabled')->default(true);
            $table->string('backend_scheme', 8)->default('http');
            $table->boolean('backend_tls_insecure')->default(false);

            $table->timestamps();

            $table->unique(['node_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pouch_routes');
    }
};
