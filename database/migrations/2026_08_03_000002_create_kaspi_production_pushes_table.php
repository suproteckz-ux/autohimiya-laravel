<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_production_pushes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->index();
            $table->string('kaspi_url');
            $table->uuid('request_id')->unique();
            $table->string('content_hash', 64)->index();
            $table->json('collected_payload');
            $table->string('status')->default('collected')->index();
            $table->string('production_status')->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_summary')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['sku', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_production_pushes');
    }
};
