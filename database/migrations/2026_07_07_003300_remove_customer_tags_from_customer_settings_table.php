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
        Schema::table('customer_settings', function (Blueprint $table) {
            $table->dropColumn(['sync_shopify_customer_tags', 'sync_zoho_customer_tags']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_settings', function (Blueprint $table) {
            $table->boolean('sync_shopify_customer_tags')->default(false);
            $table->boolean('sync_zoho_customer_tags')->default(false);
        });
    }
};
