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
        Schema::create('product_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->index();
            $table->string('sync_direction')->default('shopify-to-zoho');
            $table->boolean('sync_draft_products')->default(false);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->boolean('update_fields_enabled')->default(true);
            $table->json('export_fields')->nullable();
            $table->boolean('sync_by_collection')->default(false);
            $table->json('selected_collections')->nullable();
            $table->boolean('sync_by_tags')->default(false);
            $table->json('selected_tags')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_settings');
    }
};
