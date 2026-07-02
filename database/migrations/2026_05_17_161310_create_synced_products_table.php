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
        Schema::create('synced_products', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->index();
            $table->string('zoho_item_id');
            $table->string('shopify_product_id');
            $table->string('shopify_variant_id');
            $table->text('title');
            $table->string('sku')->nullable()->index();
            $table->string('last_sync_source');
            $table->dateTime('last_sync_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synced_products');
    }
};
