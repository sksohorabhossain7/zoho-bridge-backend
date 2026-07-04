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
        Schema::table('zoho_tokens', function (Blueprint $table) {
            $table->string('sku_mapping')->default('sku')->after('organization_name');
            $table->string('zoho_custom_field')->nullable()->after('sku_mapping');
            $table->string('sale_invoice_journal')->nullable()->after('zoho_custom_field');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zoho_tokens', function (Blueprint $table) {
            $table->dropColumn(['sku_mapping', 'zoho_custom_field', 'sale_invoice_journal']);
        });
    }
};
