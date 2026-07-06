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
        Schema::create('customer_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->index();
            $table->string('sync_direction')->default('shopify-to-zoho');
            $table->string('sync_option')->default('create_new_and_update_existing');
            $table->boolean('enable_customer_sync_shopify_to_zoho')->default(false);
            $table->boolean('enable_customer_sync_zoho_to_shopify')->default(false);
            $table->boolean('sync_shopify_customer_tags')->default(false);
            $table->boolean('sync_zoho_customer_tags')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_settings');
    }
};
