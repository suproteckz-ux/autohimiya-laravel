<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_stock_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kaspi_order_id')->nullable()->constrained('kaspi_orders')->nullOnDelete();
            $table->foreignId('kaspi_order_item_id')->nullable()->constrained('kaspi_order_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('event_type');
            $table->string('sku')->nullable();
            $table->integer('qty')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('kaspi_order_id');
            $table->index('event_type');
            $table->index('product_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_stock_event_logs');
    }
};
