<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pouch_node_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained('nodes')->cascadeOnDelete();

            // Explicit base domain for nodes whose Wings FQDN cannot produce
            // hostnames (a bare IP address). It is deliberately ignored for
            // nodes that do have a usable domain FQDN — for those the base
            // domain stays immutable.
            $table->string('proxy_domain', 253)->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pouch_node_settings');
    }
};
