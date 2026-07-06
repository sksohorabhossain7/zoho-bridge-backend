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
            $table->integer('api_calls_limit')->default(0)->after('tax_type');
            $table->integer('api_calls_remaining')->default(0)->after('api_calls_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zoho_tokens', function (Blueprint $table) {
            $table->dropColumn(['api_calls_limit', 'api_calls_remaining']);
        });
    }
};
