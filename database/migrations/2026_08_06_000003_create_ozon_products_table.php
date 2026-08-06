<?php

use App\Enums\OzonProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('ozon_warehouse_id')->nullable()->constrained('ozon_warehouses')->nullOnDelete();

            $table->string('offer_id');
            $table->string('ozon_product_id')->nullable()->index();
            $table->string('ozon_sku')->nullable()->index();
            $table->string('ozon_task_id')->nullable()->index();
            $table->string('description_category_id')->nullable()->index();
            $table->string('description_category_name')->nullable();
            $table->string('type_id')->nullable()->index();
            $table->string('type_name')->nullable();
            $table->string('status')->default(OzonProductStatus::Draft->value)->index();

            $table->string('prepared_name')->nullable();
            $table->longText('prepared_description')->nullable();
            $table->json('prepared_images')->nullable();
            $table->json('prepared_attributes')->nullable();
            $table->json('prepared_payload')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();

            $table->boolean('price_sync_enabled')->default(true);
            $table->boolean('stock_sync_enabled')->default(true);
            $table->boolean('content_sync_enabled')->default(false);
            $table->decimal('manual_ozon_price', 12, 2)->nullable();
            $table->decimal('price_multiplier', 10, 4)->nullable();
            $table->string('rounding_rule')->nullable();
            $table->unsignedInteger('stock_limit')->nullable();
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->string('tnved_code')->nullable();

            $table->decimal('calculated_price', 12, 2)->nullable();
            $table->decimal('last_sent_price', 12, 2)->nullable();
            $table->unsignedInteger('calculated_stock')->nullable();
            $table->unsignedInteger('last_sent_stock')->nullable();
            $table->timestamp('first_exported_at')->nullable();
            $table->timestamp('last_exported_at')->nullable();
            $table->timestamp('last_status_checked_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_price_synced_at')->nullable();
            $table->timestamp('last_stock_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ozon_account_id', 'product_id']);
            $table->unique(['ozon_account_id', 'offer_id']);
            $table->index(['status', 'updated_at']);
            $table->index(['ozon_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_products');
    }
};
