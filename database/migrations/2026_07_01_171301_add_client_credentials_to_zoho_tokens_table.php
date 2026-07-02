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
            $table->string('client_id')->nullable()->after('shop');
            $table->string('client_secret')->nullable()->after('client_id');
            $table->string('region')->nullable()->after('client_secret');
            
            $table->string('access_token')->nullable()->change();
            $table->string('refresh_token')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
            $table->string('api_domain')->nullable()->change();
            $table->string('accounts_server')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zoho_tokens', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'client_secret', 'region']);
            
            // To restore strictly non-nullable (ensure there is no null data before running down)
            $table->string('access_token')->nullable(false)->change();
            $table->string('refresh_token')->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
            $table->string('api_domain')->nullable(false)->change();
            $table->string('accounts_server')->nullable(false)->change();
        });
    }
};
