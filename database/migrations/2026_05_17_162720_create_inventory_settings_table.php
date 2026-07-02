<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->index();
            $table->string('sync_direction')->default('shopify-to-zoho');
            $table->boolean('auto_sync_enabled')->default(false);
            $table->string('quantity_type')->default('available');
            $table->string('sync_frequency')->default('30');
            $table->boolean('skip_zero_stock')->default(false);
            $table->json('location_mapping')->nullable();
            $table->boolean('sync_by_collection')->default(false);
            $table->json('selected_collections')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_settings');
    }
};
