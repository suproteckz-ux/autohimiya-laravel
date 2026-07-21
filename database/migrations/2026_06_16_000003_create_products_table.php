<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('opencart_product_id')->nullable()->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('paloma_id')->nullable()->index();
            $table->string('paloma_sku')->nullable()->index();
            $table->string('volume')->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('primary_image')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->string('availability_status')->default('unknown')->index();
            $table->string('product_status')->default('needs_review')->index();
            $table->string('sync_status')->default('not_found_in_paloma')->index();
            $table->string('price_source')->default('migration');
            $table->string('stock_source')->default('migration');
            $table->string('match_method')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_hit')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_sale')->default(false);
            $table->json('badges')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1')->nullable();
            $table->string('canonical_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_status', 'availability_status']);
            $table->index(['category_id', 'product_status']);
            $table->index(['brand_id', 'product_status']);
            $table->index(['is_featured', 'product_status']);
            $table->index(['is_hit', 'product_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
