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
        Schema::create('synced_customers', function (Blueprint $table) {
            $table->id();
            $table->string('shop')->index();
            $table->string('shopify_customer_id')->index();
            $table->string('zoho_contact_id')->index();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synced_customers');
    }
};
