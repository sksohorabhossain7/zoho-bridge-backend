<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_tokens', function (Blueprint $table) {
            $table->boolean('enable_fixed_tax')->default(false)->after('sale_invoice_journal');
            $table->string('tax_type')->nullable()->after('enable_fixed_tax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zoho_tokens', function (Blueprint $table) {
            $table->dropColumn(['enable_fixed_tax', 'tax_type']);
        });
    }
};
