<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_enrichment_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kaspi_merchant_sku')->nullable()->index();
            $table->string('kaspi_product_url')->nullable();
            $table->boolean('missing_photo')->default(false)->index();
            $table->boolean('missing_description')->default(false)->index();
            $table->boolean('missing_attributes')->default(false)->index();
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('manual')->index();
            $table->json('parsed_title')->nullable();
            $table->json('parsed_images')->nullable();
            $table->longText('parsed_description')->nullable();
            $table->json('parsed_attributes')->nullable();
            $table->string('parsed_brand')->nullable();
            $table->string('parsed_category')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_enrichment_tasks');
    }
};
