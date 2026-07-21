<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_publish_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kaspi_enrichment_task_id')->nullable()->constrained('kaspi_enrichment_tasks')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor')->nullable();
            $table->json('published_fields')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->string('status')->index();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'created_at']);
            $table->index(['kaspi_enrichment_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_publish_logs');
    }
};
