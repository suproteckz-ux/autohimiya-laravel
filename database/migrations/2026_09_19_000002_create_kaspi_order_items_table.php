<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kaspi_order_id')->constrained('kaspi_orders')->cascadeOnDelete();
            $table->string('kaspi_entry_id')->nullable()->unique();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('merchant_sku')->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->string('sku_match_status')->default('unmatched');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('merchant_sku');
            $table->index('product_id');
            $table->index('sku_match_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_order_items');
    }
};
