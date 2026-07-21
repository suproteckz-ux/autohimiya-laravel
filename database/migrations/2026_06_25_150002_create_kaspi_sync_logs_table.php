<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('source')->nullable()->index();
            $table->string('url')->nullable();
            $table->string('merchant_sku')->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_size')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status')->index();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_sync_logs');
    }
};
