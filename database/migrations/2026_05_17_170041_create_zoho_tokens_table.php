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
        Schema::create('zoho_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->unique();
            $table->string('access_token');
            $table->string('refresh_token');
            $table->timestamp('expires_at');
            $table->string('api_domain');
            $table->string('accounts_server');
            $table->string('organization_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zoho_tokens');
    }
};
