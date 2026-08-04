<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_import_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('sku')->index();
            $table->string('normalized_sku')->index();
            $table->string('content_hash', 64)->index();
            $table->timestamp('received_at')->index();
            $table->timestamp('collected_at')->nullable();
            $table->string('status')->index();
            $table->json('result_summary')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->timestamps();

            $table->index(['normalized_sku', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_import_receipts');
    }
};
